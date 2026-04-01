<?php
/**
 * Halaman Utama Aplikasi - Dengan Konfigurasi Terpusat dari settings.php
 * File: index.php
 * 
 * PERBAIKAN:
 * - Mengambil konfigurasi MailerSend dan Fonnte dari settings.php
 * - Mempertahankan semua fungsi STEP 5,6,7 yang sudah berjalan dengan baik
 * - Menambahkan opsi "siswa" pada identitas
 * - Menambahkan fitur Lacak Pesan dengan visualisasi timeline
 * - PERBAIKAN: Tampilan lebih ramping (20% lebih kecil) & menghilangkan auto-refresh
 * - PERBAIKAN: Menampilkan review dari Wakil Kepala Sekolah dan Kepala Sekolah
 * - PERBAIKAN: Fungsi tracking dengan user_type & review yang benar
 * - PENAMBAHAN: Fitur upload gambar lampiran (opsional) untuk Kirim Pesan Tanpa Login
 * - PERBAIKAN: Struktur tabel message_attachments sesuai dengan database yang ada
 * - PERBAIKAN: Upload gambar menggunakan method execute() bukan prepare()
 * - PERBAIKAN: Menghilangkan nilai id dari INSERT (auto_increment) dan user_id menggunakan nilai aman
 */

// Aktifkan error reporting maksimal
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

// ============================================================================
// DEFINISI KONSTANTA FILE LOG DAN UPLOAD
// ============================================================================
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

// Buat folder upload jika belum ada
$uploadDir = __DIR__ . '/uploads/external_messages';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

define('INDEX_DEBUG_LOG', $logDir . '/index_debug.log');
define('INDEX_EMAIL_LOG', $logDir . '/index_email.log');
define('INDEX_WHATSAPP_LOG', $logDir . '/index_whatsapp.log');
define('INDEX_UPLOAD_LOG', $logDir . '/index_upload.log');

// ============================================================================
// FUNGSI LOGGING
// ============================================================================
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

function debug_log($message, $data = null) {
    writeLog(INDEX_DEBUG_LOG, $message, $data);
}

function email_log($message, $data = null) {
    writeLog(INDEX_EMAIL_LOG, $message, $data);
}

function wa_log($message, $data = null) {
    writeLog(INDEX_WHATSAPP_LOG, $message, $data);
}

function upload_log($message, $data = null) {
    writeLog(INDEX_UPLOAD_LOG, $message, $data);
}

debug_log("========== INDEX.PHP START ==========");

// ============================================================================
// LOAD KONFIGURASI TERPUSAT DARI SETTINGS.PHP
// ============================================================================
$mailersendConfig = [];
$fonnteConfig = [];

// Load MailerSend config
$mailersendConfigFile = ROOT_PATH . '/config/mailersend.json';
if (file_exists($mailersendConfigFile)) {
    $mailersendConfig = json_decode(file_get_contents($mailersendConfigFile), true);
} else {
    // Default config jika file belum ada
    $mailersendConfig = [
        'api_token' => 'mlsn.3ae3e0dd1dec6af1d2aa199c1d20287ee29e9fcda6679c4ff4049a7c26061d29',
        'domain' => 'test-r9084zv6rpjgw63d.mlsender.net',
        'from_email' => 'noreply@test-r9084zv6rpjgw63d.mlsender.net',
        'from_name' => 'SMKN 12 Jakarta - Aplikasi Pesan Responsif',
        'is_active' => 1
    ];
}

// Load Fonnte config
$fonnteConfigFile = ROOT_PATH . '/config/fonnte.json';
if (file_exists($fonnteConfigFile)) {
    $fonnteConfig = json_decode(file_get_contents($fonnteConfigFile), true);
} else {
    // Default config jika file belum ada
    $fonnteConfig = [
        'api_token' => 'FS2cq8FckmaTegxtZpFB',
        'api_url' => 'https://api.fonnte.com/send',
        'device_id' => '6285174207795',
        'country_code' => '62',
        'is_active' => 1
    ];
}

// ============================================================================
// FUNGSI UPLOAD GAMBAR UNTUK PESAN EKSTERNAL - SESUAI STRUKTUR TABEL
// ============================================================================
function handleExternalImageUpload($file, $reference, $external_sender_id, $user_id = null) {
    upload_log("========== FUNGSI UPLOAD GAMBAR EXTERNAL ==========");
    upload_log("Processing upload for reference: $reference, external_sender_id: $external_sender_id, user_id: " . ($user_id ?: 'NULL'));
    
    // Cek apakah ada file yang diupload
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        upload_log("Tidak ada file yang diupload (opsional)");
        return ['success' => true, 'uploaded' => false, 'message' => 'Tidak ada file'];
    }
    
    // Cek error upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File melebihi ukuran maksimum yang diizinkan server (upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE => 'File melebihi ukuran maksimum yang diizinkan form',
            UPLOAD_ERR_PARTIAL => 'File hanya terupload sebagian',
            UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diupload',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary tidak ditemukan',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk',
            UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi PHP'
        ];
        $error = $errorMessages[$file['error']] ?? 'Unknown upload error';
        upload_log("ERROR: " . $error);
        return ['success' => false, 'error' => $error];
    }
    
    // Validasi ukuran file (max 5MB)
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        upload_log("ERROR: File terlalu besar - " . $file['size'] . " bytes (max 5MB)");
        return ['success' => false, 'error' => 'Ukuran file maksimal 5MB'];
    }
    
    // Validasi tipe file
    $allowedTypes = [
        'image/jpeg', 'image/jpg', 'image/pjpeg',
        'image/png', 
        'image/gif',
        'image/webp',
        'image/heic', 'image/heif', // Format iPhone
        'image/bmp',
        'image/x-ms-bmp'
    ];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    // Ekstensi yang diizinkan
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    upload_log("File info", [
        'original_name' => $file['name'],
        'size' => $file['size'],
        'tmp_name' => $file['tmp_name'],
        'mime_detected' => $mimeType,
        'extension' => $extension
    ]);
    
    if (!in_array($mimeType, $allowedTypes) && !in_array($extension, $allowedExtensions)) {
        upload_log("ERROR: Tipe file tidak diizinkan - MIME: $mimeType, Ext: $extension");
        return ['success' => false, 'error' => 'Tipe file tidak diizinkan. Gunakan JPG, JPEG, PNG, GIF, WEBP, HEIC, atau BMP'];
    }
    
    // Buat nama file unik dengan struktur folder berdasarkan tanggal
    $dateFolder = date('Y/m/d');
    $fullUploadDir = __DIR__ . '/uploads/external_messages/' . $dateFolder;
    if (!is_dir($fullUploadDir)) {
        mkdir($fullUploadDir, 0777, true);
    }
    
    $timestamp = time();
    $random = bin2hex(random_bytes(8));
    $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    $newFileName = "ext_{$reference}_{$timestamp}_{$random}.{$extension}";
    
    // Tentukan path upload relatif untuk database
    $relativePath = 'uploads/external_messages/' . $dateFolder . '/' . $newFileName;
    $absolutePath = __DIR__ . '/' . $relativePath;
    
    upload_log("Target path: " . $absolutePath);
    
    // Pindahkan file
    if (move_uploaded_file($file['tmp_name'], $absolutePath)) {
        chmod($absolutePath, 0644); // Set permission
        
        upload_log("✓ FILE BERHASIL DIUPLOAD", [
            'new_filename' => $newFileName,
            'path' => $absolutePath,
            'size' => $file['size']
        ]);
        
        return [
            'success' => true,
            'uploaded' => true,
            'filename' => $newFileName, // Untuk kolom filename
            'filepath' => $relativePath, // Untuk kolom filepath
            'original_name' => $file['name'],
            'filesize' => $file['size'], // Untuk kolom filesize
            'filetype' => $extension, // Untuk kolom filetype
            'mime_type' => $mimeType
        ];
    } else {
        upload_log("✗ GAGAL MEMINDAHKAN FILE");
        return ['success' => false, 'error' => 'Gagal menyimpan file'];
    }
}

// ============================================================
// FUNGSI UNTUK MENDAPATKAN URL BASE
// ============================================================
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $scriptName = $_SERVER['SCRIPT_NAME'];
    $path = str_replace(basename($scriptName), '', $scriptName);
    return $protocol . $host . $path;
}

// Dapatkan URL lengkap untuk QR Code
$baseUrl = getBaseUrl();
$fullUrl = $baseUrl . 'index.php';

// ============================================================
// DEBUG FUNCTION - DETAIL BERTAHAP
// ============================================================
$debug_steps = [];
$step_counter = 0;

function debug_step($title, $data = null, $type = 'info') {
    global $debug_steps, $step_counter;
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
    
    $log_message = "[DEBUG][{$current_date}][STEP {$step_counter}][{$caller}:{$line}] {$title}";
    if ($data !== null) {
        $log_message .= " - " . print_r($data, true);
    }
    error_log($log_message);
    
    $debug_file = __DIR__ . '/debug_history.log';
    $fp = fopen($debug_file, 'a');
    fwrite($fp, $log_message . "\n");
    fclose($fp);
}

// ============================================================
// TEST KONEKSI DATABASE
// ============================================================
try {
    debug_step("TEST KONEKSI DATABASE - MULAI");
    
    $db = Database::getInstance();
    debug_step("Database::getInstance()", ['class' => get_class($db)]);
    
    $conn = $db->getConnection();
    debug_step("getConnection()", ['status' => $conn ? 'OK' : 'FAILED']);
    
    if (!$conn) {
        throw new Exception("Tidak dapat konek ke database");
    }
    
    $test = $db->select("SELECT 1");
    debug_step("TEST QUERY 'SELECT 1'", ['result' => $test]);
    
    debug_step("✓ DATABASE SIAP DIGUNAKAN");
    
} catch (Exception $e) {
    debug_step("✗ DATABASE ERROR", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], 'error');
    
    $db_error = "<div style='background: #f8d7da; border:2px solid #dc3545; padding:20px; border-radius:10px; margin-bottom:20px;'>";
    $db_error .= "<h3 style='color:#721c24;'><i class='fas fa-exclamation-triangle'></i> ERROR DATABASE</h3>";
    $db_error .= "<p style='color:#721c24;'>{$e->getMessage()}</p>";
    $db_error .= "</div>";
}

// ============================================================
// CEK TABEL MESSAGE_ATTACHMENTS (SESUAI STRUKTUR)
// ============================================================
try {
    $check_table = $db->select("SHOW TABLES LIKE 'message_attachments'");
    if (empty($check_table)) {
        debug_step("MEMBUAT TABEL message_attachments sesuai struktur");
        
        $sql = "CREATE TABLE IF NOT EXISTS message_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            filename VARCHAR(255) NOT NULL,
            filepath VARCHAR(500) NOT NULL,
            filetype VARCHAR(50),
            filesize BIGINT,
            is_approved TINYINT(1) DEFAULT 1,
            virus_scan_status ENUM('Pending', 'Clean', 'Infected', 'Error') DEFAULT 'Pending',
            download_count INT DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_message_id (message_id),
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $db->execute($sql);
        debug_step("✓ TABEL message_attachments BERHASIL DIBUAT sesuai struktur");
    } else {
        debug_step("✓ TABEL message_attachments sudah ada");
        
        // Pastikan kolom id memiliki AUTO_INCREMENT
        $check_auto_increment = $db->select("SHOW COLUMNS FROM message_attachments WHERE Field = 'id' AND Extra LIKE '%auto_increment%'");
        if (empty($check_auto_increment)) {
            debug_step("⚠️ Kolom id belum memiliki AUTO_INCREMENT, memperbaiki...");
            $db->execute("ALTER TABLE message_attachments MODIFY id INT AUTO_INCREMENT");
            debug_step("✓ Kolom id telah diubah menjadi AUTO_INCREMENT");
        }
    }
    
    // Cek apakah kolom has_attachments sudah ada di tabel messages
    $check_column = $db->select("SHOW COLUMNS FROM messages LIKE 'has_attachments'");
    if (empty($check_column)) {
        debug_step("MENAMBAHKAN KOLOM has_attachments ke tabel messages");
        $db->execute("ALTER TABLE messages ADD COLUMN has_attachments TINYINT(1) DEFAULT 0 AFTER whatsapp_notified");
        debug_step("✓ KOLOM has_attachments BERHASIL DITAMBAHKAN");
    }
    
} catch (Exception $e) {
    debug_step("✗ ERROR saat cek tabel: " . $e->getMessage());
}

// ============================================================
// GENERATE UNIQUE TOKEN
// ============================================================
if (!isset($_SESSION['form_unique_id'])) {
    $_SESSION['form_unique_id'] = bin2hex(random_bytes(16));
}

// ============================================================================
// FUNGSI FORMAT NOMOR WHATSAPP (SAMA DENGAN SETTINGS.PHP)
// ============================================================================
function formatPhoneNumber($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    if (substr($phone, 0, 1) == '0') {
        $phone = '62' . substr($phone, 1);
    }
    elseif (substr($phone, 0, 2) !== '62') {
        $phone = '62' . $phone;
    }
    
    return $phone;
}

// ============================================================================
// FUNGSI GET NAMA JENIS PESAN
// ============================================================================
function getJenisPesanName($db, $jenis_pesan_id) {
    try {
        $result = $db->select("SELECT jenis_pesan FROM message_types WHERE id = ?", [$jenis_pesan_id]);
        return !empty($result) ? $result[0]['jenis_pesan'] : 'Tidak diketahui';
    } catch (Exception $e) {
        debug_log("Gagal ambil nama jenis pesan: " . $e->getMessage());
        return 'Tidak diketahui';
    }
}

// ============================================================================
// FUNGSI KIRIM WHATSAPP - MENGGUNAKAN KONFIGURASI DARI SETTINGS.PHP
// ============================================================================
function kirimWhatsApp($phone, $nama, $reference, $isi_pesan, $jenis_pesan, $prioritas) {
    global $fonnteConfig;
    
    wa_log("========== FUNGSI KIRIM WHATSAPP ==========");
    wa_log("Input - phone: $phone, nama: $nama, reference: $reference");
    wa_log("Jenis Pesan: $jenis_pesan, Prioritas: $prioritas");
    
    // Cek apakah Fonnte aktif
    if (!isset($fonnteConfig['is_active']) || $fonnteConfig['is_active'] != 1) {
        wa_log("Fonnte tidak aktif, lewati pengiriman WhatsApp");
        return ['success' => false, 'sent' => false, 'error' => 'Layanan WhatsApp tidak aktif'];
    }
    
    // Format nomor
    $formatted_phone = formatPhoneNumber($phone);
    wa_log("Phone setelah format: $formatted_phone");
    
    if (strlen($formatted_phone) < 10 || strlen($formatted_phone) > 15) {
        wa_log("ERROR: Nomor tidak valid - panjang: " . strlen($formatted_phone));
        return ['success' => false, 'error' => 'Nomor tidak valid'];
    }
    
    // Batasi panjang pesan untuk WhatsApp (max 1000 karakter)
    $isi_pesan_short = strlen($isi_pesan) > 500 ? substr($isi_pesan, 0, 500) . '...' : $isi_pesan;
    
    // Mapping prioritas ke emoji
    $priorityEmoji = [
        'Low' => '🔵',
        'Medium' => '🟡',
        'High' => '🔴'
    ];
    $priorityEmoji = $priorityEmoji[$prioritas] ?? '⚪';
    
    // Pesan notifikasi dengan isi pesan asli dan jenis pesan
    $message = "🔔 *NOTIFIKASI PESAN - SMKN 12 Jakarta*\n\n";
    $message .= "Yth. *$nama*\n\n";
    $message .= "Pesan Anda telah berhasil kami terima.\n\n";
    $message .= "*📋 INFORMASI PESAN:*\n";
    $message .= str_repeat("─", 25) . "\n";
    $message .= "📌 Nomor Referensi: $reference\n";
    $message .= "📂 Jenis Pesan: $jenis_pesan\n";
    $message .= "$priorityEmoji Prioritas: $prioritas\n";
    $message .= "📊 Status: Pending (Menunggu diproses)\n";
    $message .= str_repeat("─", 25) . "\n\n";
    
    $message .= "*📝 ISI PESAN ANDA:*\n";
    $message .= "```\n" . $isi_pesan_short . "\n```\n\n";
    
    $message .= "Pesan akan diproses dalam 1x24 jam.\n\n";
    $message .= "Terima kasih telah menghubungi SMKN 12 Jakarta.\n\n";
    $message .= "_Pesan otomatis._";
    
    wa_log("Pesan panjang: " . strlen($message));
    
    // Kirim via Fonnte
    $postData = [
        'target' => $formatted_phone,
        'message' => $message,
        'countryCode' => $fonnteConfig['country_code'] ?? '62',
        'delay' => '0'
    ];
    
    wa_log("Data ke Fonnte:", $postData);
    wa_log("URL: " . ($fonnteConfig['api_url'] ?? 'https://api.fonnte.com/send'));
    wa_log("API Token: " . substr($fonnteConfig['api_token'] ?? '', 0, 5) . '...');
    
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
    
    wa_log("HTTP Code: $httpCode");
    if ($curlError) {
        wa_log("CURL Error: $curlError");
    }
    wa_log("Response: $response");
    
    $response_data = json_decode($response, true);
    
    $success = false;
    if ($httpCode == 200) {
        if (isset($response_data['status']) && ($response_data['status'] === true || $response_data['status'] == 1)) {
            $success = true;
        } elseif (isset($response_data['id'])) {
            $success = true;
        }
    }
    
    if ($success) {
        wa_log("✓ WHATSAPP BERHASIL dikirim ke $formatted_phone");
    } else {
        wa_log("✗ WHATSAPP GAGAL dikirim ke $formatted_phone");
        if (isset($response_data['reason'])) {
            wa_log("Reason: " . $response_data['reason']);
        }
    }
    
    return [
        'success' => $success,
        'sent' => $success,
        'error' => $curlError ?: ($response_data['reason'] ?? 'Unknown error')
    ];
}

// ============================================================================
// FUNGSI KIRIM EMAIL - MENGGUNAKAN KONFIGURASI DARI SETTINGS.PHP
// ============================================================================
function kirimEmail($email, $nama, $reference, $isi_pesan, $jenis_pesan, $prioritas) {
    global $mailersendConfig;
    
    email_log("========== FUNGSI KIRIM EMAIL ==========");
    email_log("Input - email: $email, nama: $nama, reference: $reference");
    email_log("Jenis Pesan: $jenis_pesan, Prioritas: $prioritas");
    
    // Cek apakah MailerSend aktif
    if (!isset($mailersendConfig['is_active']) || $mailersendConfig['is_active'] != 1) {
        email_log("MailerSend tidak aktif, lewati pengiriman email");
        return ['success' => false, 'sent' => false, 'error' => 'Layanan email tidak aktif'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        email_log("ERROR: Email tidak valid");
        return ['success' => false, 'error' => 'Email tidak valid'];
    }
    
    if (empty($mailersendConfig['api_token'])) {
        email_log("ERROR: API Token kosong");
        return ['success' => false, 'error' => 'API Token tidak boleh kosong'];
    }
    
    if (empty($mailersendConfig['from_email'])) {
        email_log("ERROR: From Email kosong");
        return ['success' => false, 'error' => 'From Email tidak boleh kosong'];
    }
    
    $subject = "Konfirmasi Pesan #$reference - SMKN 12 Jakarta";
    
    // Mapping prioritas ke badge warna
    $priorityBadge = [
        'Low' => '<span style="background: #17a2b8; color: white; padding: 3px 10px; border-radius: 12px; font-size: 12px;">🔵 Rendah</span>',
        'Medium' => '<span style="background: #ffc107; color: #212529; padding: 3px 10px; border-radius: 12px; font-size: 12px;">🟡 Sedang</span>',
        'High' => '<span style="background: #dc3545; color: white; padding: 3px 10px; border-radius: 12px; font-size: 12px;">🔴 Tinggi</span>'
    ];
    $priorityBadge = $priorityBadge[$prioritas] ?? '<span style="background: #6c757d; color: white; padding: 3px 10px; border-radius: 12px; font-size: 12px;">⚪ ' . $prioritas . '</span>';
    
    // HTML Email dengan format yang lebih informatif
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; background-color: #f4f6f9; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #0b4d8a, #1a73e8); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 28px; }
        .header p { margin: 10px 0 0; opacity: 0.9; }
        .content { padding: 30px; }
        .info-box { background: #f8f9fa; border-left: 4px solid #0b4d8a; padding: 20px; margin: 20px 0; border-radius: 0 5px 5px 0; }
        .reference-box { background: #e8f0fe; padding: 15px; border-radius: 5px; text-align: center; margin: 20px 0; }
        .reference { font-family: monospace; font-size: 18px; color: #0b4d8a; font-weight: bold; }
        .message-box { background: #f8f9fa; padding: 20px; border: 1px solid #dee2e6; border-radius: 5px; margin: 15px 0; }
        .message-content { background: white; padding: 15px; border-radius: 5px; white-space: pre-line; font-style: italic; color: #495057; }
        .detail-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .detail-table td { padding: 10px; border-bottom: 1px solid #e9ecef; }
        .detail-table td:first-child { font-weight: bold; color: #495057; width: 120px; }
        .btn { display: inline-block; background: #0b4d8a; color: white; padding: 12px 30px; text-decoration: none; border-radius: 50px; font-weight: bold; margin: 10px 0; }
        .footer { background: #e9ecef; padding: 20px; text-align: center; color: #6c757d; font-size: 12px; border-top: 1px solid #dee2e6; }
        .priority-badge { display: inline-block; margin-left: 10px; }
        .message-preview { max-height: 200px; overflow-y: auto; }
        .attachment-info { background: #e7f3ff; padding: 10px; border-radius: 5px; margin-top: 10px; }
        .attachment-item { background: #f1f8ff; border: 1px solid #b8daff; border-radius: 4px; padding: 5px 10px; margin: 5px 0; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 SMKN 12 Jakarta</h1>
            <p>Konfirmasi Penerimaan Pesan</p>
        </div>
        
        <div class="content">
            <p>Yth. <strong>' . htmlspecialchars($nama) . '</strong>,</p>
            
            <p>Terima kasih telah menghubungi SMKN 12 Jakarta melalui Aplikasi Pesan Responsif.</p>
            
            <div class="reference-box">
                <p style="margin: 0; color: #6c757d;">Nomor Referensi Pesan Anda:</p>
                <p class="reference">' . htmlspecialchars($reference) . '</p>
            </div>
            
            <div class="info-box">
                <h4 style="margin-top: 0; color: #0b4d8a;">📋 DETAIL PESAN</h4>
                
                <table class="detail-table">
                    <tr>
                        <td>Jenis Pesan</td>
                        <td><strong>' . htmlspecialchars($jenis_pesan) . '</strong></td>
                    </tr>
                    <tr>
                        <td>Prioritas</td>
                        <td>' . $priorityBadge . '</td>
                    </tr>
                    <tr>
                        <td>Status</td>
                        <td><span style="background: #ffc107; color: #212529; padding: 3px 10px; border-radius: 12px;">Pending</span> (Menunggu Diproses)</td>
                    </tr>
                    <tr>
                        <td>Estimasi Respons</td>
                        <td>1x24 jam kerja</td>
                    </tr>
                </table>
            </div>
            
            <div class="message-box">
                <h4 style="margin-top: 0; color: #0b4d8a;">📝 ISI PESAN ANDA</h4>
                <div class="message-content message-preview">
                    ' . nl2br(htmlspecialchars($isi_pesan)) . '
                </div>
            </div>';
            
            // Tambahkan informasi lampiran jika ada (akan diisi setelah upload)
            if (isset($_SESSION['uploaded_files']) && !empty($_SESSION['uploaded_files'])) {
                $html .= '
            <div class="attachment-info">
                <p><strong>📎 Lampiran:</strong> ' . count($_SESSION['uploaded_files']) . ' file gambar</p>';
                
                foreach ($_SESSION['uploaded_files'] as $file) {
                    $html .= '<div class="attachment-item">
                        <i class="fas fa-image"></i> ' . htmlspecialchars($file['original_name']) . ' (' . round($file['filesize'] / 1024, 1) . ' KB)
                    </div>';
                }
                
                $html .= '</div>';
            }
            
            $html .= '
            
            <p><strong>PENTING:</strong> Simpan nomor referensi di atas untuk mengecek status pesan Anda.</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . BASE_URL . 'index.php" class="btn">🏫 Kembali ke Beranda</a>
            </div>
        </div>
        
        <div class="footer">
            <p>&copy; ' . date('Y') . ' SMKN 12 Jakarta. All rights reserved.</p>
            <p><i>Powered by MailerSend</i></p>
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
    
    email_log("Data email:", [
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
    
    email_log("HTTP Code: $httpCode");
    if ($curlError) {
        email_log("CURL Error: $curlError");
    }
    email_log("Response: $response");
    
    $response_data = json_decode($response, true);
    $success = ($httpCode >= 200 && $httpCode < 300);
    
    if ($success) {
        email_log("✓ EMAIL BERHASIL dikirim ke $email");
    } else {
        email_log("✗ EMAIL GAGAL dikirim ke $email");
        if (isset($response_data['message'])) {
            email_log("Pesan error: " . $response_data['message']);
        }
    }
    
    return [
        'success' => $success,
        'sent' => $success,
        'error' => $curlError ?: ($response_data['message'] ?? 'Unknown error')
    ];
}

// ============================================================================
// FUNGSI GET TRACKING STATUS - FITUR LACAK PESAN (PERBAIKAN DENGAN USER_TYPE & REVIEW)
// ============================================================================
function getMessageTracking($db, $reference_number) {
    try {
        debug_log("=== GET MESSAGE TRACKING ===");
        debug_log("Mencari pesan dengan reference: '" . $reference_number . "'");
        
        // Query untuk mendapatkan data pesan utama
        $sql = "SELECT m.*, 
                       mt.jenis_pesan,
                       mt.response_deadline_hours
                FROM messages m
                LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
                WHERE m.reference_number = :ref
                LIMIT 1";
        
        $params = [':ref' => $reference_number];
        
        debug_log("SQL Query pesan:", $sql);
        
        $result = $db->select($sql, $params);
        
        if (empty($result)) {
            debug_log("Pesan TIDAK DITEMUKAN untuk reference: " . $reference_number);
            
            // Cek semua reference_number yang ada di database untuk debug
            $all_refs = $db->select("SELECT id, reference_number FROM messages ORDER BY id DESC LIMIT 20");
            debug_log("20 reference terakhir di database:", $all_refs);
            
            return [
                'error' => 'not_found',
                'message' => 'Pesan tidak ditemukan',
                'reference' => $reference_number
            ];
        }
        
        $message = $result[0];
        debug_log("✓ PESAN DITEMUKAN:", [
            'id' => $message['id'],
            'reference' => $message['reference_number'],
            'status' => $message['status'],
            'pengirim' => $message['pengirim_nama']
        ]);
        
        // Cek apakah pesan sudah expired
        $is_expired = false;
        if (!empty($message['expired_at']) && strtotime($message['expired_at']) < time()) {
            $is_expired = true;
            debug_log("Pesan expired pada: " . $message['expired_at']);
        }
        
        // AMBIL DATA RESPONSES - Dari guru responder
        debug_log("Mencoba mengambil responses untuk message_id: " . $message['id']);
        
        $responses = [];
        $check_table = $db->select("SHOW TABLES LIKE 'message_responses'");
        if (!empty($check_table)) {
            $sql_responses = "SELECT r.*, 
                                     u.nama_lengkap as responder_name,
                                     u.user_type
                              FROM message_responses r
                              LEFT JOIN users u ON r.responder_id = u.id
                              WHERE r.message_id = :message_id
                              ORDER BY r.created_at ASC";
            
            $responses = $db->select($sql_responses, [':message_id' => $message['id']]);
            debug_log("Jumlah responses ditemukan: " . count($responses));
        }
        
        // AMBIL DATA REVIEW - Dari Wakil Kepala Sekolah dan Kepala Sekolah
        $reviews = [];
        $check_reviews = $db->select("SHOW TABLES LIKE 'wakepsek_reviews'");
        if (!empty($check_reviews)) {
            $sql_reviews = "SELECT wr.*, 
                                   u.nama_lengkap as reviewer_name,
                                   u.user_type
                            FROM wakepsek_reviews wr
                            LEFT JOIN users u ON wr.reviewer_id = u.id
                            WHERE wr.message_id = :message_id
                            ORDER BY wr.created_at ASC";
            
            $reviews = $db->select($sql_reviews, [':message_id' => $message['id']]);
            debug_log("Jumlah reviews ditemukan: " . count($reviews));
        }
        
        // Build tracking data
        $tracking = [
            'message' => $message,
            'is_expired' => $is_expired,
            'responses' => [],
            'reviews' => $reviews,
            'current_level' => 1,
            'max_level' => 4, // Ubah menjadi 4 level (Pesan, Respon Guru, Review Wakasek, Review Kepsek)
            'message_status' => $message['status'] ?? 'Pending'
        ];
        
        // Level 1: Pesan Dikirim
        $tracking['responses'][1] = [
            'level' => 1,
            'type' => 'Pesan Dikirim',
            'title' => 'Pesan Dikirim',
            'subtitle' => $message['pengirim_nama'] ?? 'Pengirim',
            'icon' => 'fas fa-paper-plane',
            'time' => $message['tanggal_pesan'] ?? $message['created_at'],
            'content' => $message['isi_pesan'] ?? '',
            'is_completed' => true,
            'is_current' => false,
            'is_locked' => false,
            'show_content' => true // Tampilkan isi pesan
        ];
        
        // Level 2: Respon Guru & Keputusan (gabungan)
        if (!empty($responses)) {
            $response = $responses[0];
            
            // Format user_type untuk ditampilkan
            $user_type_display = str_replace('_', ' ', $response['user_type'] ?? 'Guru');
            
            // Tentukan status badge berdasarkan status pesan
            $status_text = $message['status'] ?? 'Diproses';
            $status_class = '';
            if ($message['status'] == 'Disetujui') {
                $status_class = 'success';
            } elseif ($message['status'] == 'Ditolak') {
                $status_class = 'danger';
            } else {
                $status_class = 'info';
            }
            
            $tracking['responses'][2] = [
                'level' => 2,
                'type' => 'Respon Guru',
                'title' => $user_type_display, // Langsung menampilkan user_type
                'subtitle' => $response['responder_name'] ?? 'Guru',
                'icon' => 'fas fa-chalkboard-teacher',
                'time' => $response['responded_at'] ?? $response['created_at'],
                'content' => $response['response_text'] ?? '',
                'keputusan' => [
                    'status' => $message['status'] ?? 'Diproses',
                    'catatan' => $message['catatan_respon'] ?? '',
                    'waktu' => $message['tanggal_respon'] ?? $message['updated_at']
                ],
                'is_completed' => true,
                'is_current' => false,
                'is_locked' => false,
                'show_content' => true // Tampilkan isi respon guru
            ];
            $tracking['current_level'] = 2;
        } else {
            // Belum ada respon guru
            $tracking['responses'][2] = [
                'level' => 2,
                'type' => 'Menunggu Respon',
                'title' => 'Guru Responder',
                'subtitle' => 'Belum ada respon',
                'icon' => 'fas fa-chalkboard-teacher',
                'time' => null,
                'content' => null,
                'keputusan' => null,
                'is_completed' => false,
                'is_current' => true,
                'is_locked' => false,
                'show_content' => false
            ];
        }
        
        // Level 3: Review Wakil Kepala Sekolah (tanpa isi catatan)
        $wakepsek_review = null;
        foreach ($reviews as $review) {
            if ($review['user_type'] === 'Wakil_Kepala') {
                $wakepsek_review = $review;
                break;
            }
        }
        
        if ($wakepsek_review) {
            $tracking['responses'][3] = [
                'level' => 3,
                'type' => 'Review Wakil Kepala',
                'title' => 'Wakil Kepala Sekolah',
                'subtitle' => $wakepsek_review['reviewer_name'] ?? 'Wakil Kepala',
                'icon' => 'fas fa-user-tie',
                'time' => $wakepsek_review['created_at'],
                'content' => null, // Tidak menampilkan isi catatan
                'is_completed' => true,
                'is_current' => false,
                'is_locked' => false,
                'show_content' => false // Jangan tampilkan isi review
            ];
            $tracking['current_level'] = 3;
        } else {
            $is_locked = ($tracking['current_level'] < 2);
            $tracking['responses'][3] = [
                'level' => 3,
                'type' => 'Review Wakil Kepala',
                'title' => 'Wakil Kepala Sekolah',
                'subtitle' => ($tracking['current_level'] >= 2 && !$is_locked) ? 'Menunggu review' : 'Belum tersedia',
                'icon' => 'fas fa-user-tie',
                'time' => null,
                'content' => null,
                'is_completed' => false,
                'is_current' => ($tracking['current_level'] == 2 && !$is_locked),
                'is_locked' => $is_locked,
                'show_content' => false
            ];
        }
        
        // Level 4: Review Kepala Sekolah (tanpa isi catatan)
        $kepsek_review = null;
        foreach ($reviews as $review) {
            if ($review['user_type'] === 'Kepala_Sekolah') {
                $kepsek_review = $review;
                break;
            }
        }
        
        if ($kepsek_review) {
            $tracking['responses'][4] = [
                'level' => 4,
                'type' => 'Review Kepala Sekolah',
                'title' => 'Kepala Sekolah',
                'subtitle' => $kepsek_review['reviewer_name'] ?? 'Kepala Sekolah',
                'icon' => 'fas fa-crown',
                'time' => $kepsek_review['created_at'],
                'content' => null, // Tidak menampilkan isi catatan
                'is_completed' => true,
                'is_current' => false,
                'is_locked' => false,
                'show_content' => false // Jangan tampilkan isi review
            ];
            $tracking['current_level'] = 4;
        } else {
            $is_locked = ($tracking['current_level'] < 3);
            $tracking['responses'][4] = [
                'level' => 4,
                'type' => 'Review Kepala Sekolah',
                'title' => 'Kepala Sekolah',
                'subtitle' => ($tracking['current_level'] >= 3 && !$is_locked) ? 'Menunggu review' : 'Belum tersedia',
                'icon' => 'fas fa-crown',
                'time' => null,
                'content' => null,
                'is_completed' => false,
                'is_current' => ($tracking['current_level'] == 3 && !$is_locked),
                'is_locked' => $is_locked,
                'show_content' => false
            ];
        }
        
        debug_log("✓ Tracking data berhasil dibuat", [
            'current_level' => $tracking['current_level'],
            'message_status' => $tracking['message_status'],
            'is_expired' => $tracking['is_expired']
        ]);
        
        return $tracking;
        
    } catch (Exception $e) {
        debug_log("✗ ERROR in getMessageTracking: " . $e->getMessage());
        debug_log("Stack trace: " . $e->getTraceAsString());
        
        return [
            'error' => 'exception',
            'message' => $e->getMessage(),
            'reference' => $reference_number
        ];
    }
}

// ============================================================================
// FUNGSI RENDER TRACKING VISUALIZATION - VERSION RAMPING (20% LEBIH KECIL)
// ============================================================================
function renderTrackingVisualization($tracking) {
    // Cek jika tracking adalah array error
    if (isset($tracking['error'])) {
        if ($tracking['error'] == 'not_found') {
            return '<div class="alert alert-warning py-2 small">
                <i class="fas fa-exclamation-triangle me-1"></i> 
                Pesan dengan nomor referensi <strong>' . htmlspecialchars($tracking['reference']) . '</strong> tidak ditemukan.
            </div>';
        } elseif ($tracking['error'] == 'exception') {
            return '<div class="alert alert-danger py-2 small">
                <i class="fas fa-exclamation-circle me-1"></i> 
                Terjadi kesalahan sistem: ' . htmlspecialchars($tracking['message']) . '
            </div>';
        }
    }
    
    if (!$tracking || !is_array($tracking) || !isset($tracking['message'])) {
        return '<div class="alert alert-warning py-2 small"><i class="fas fa-exclamation-triangle me-1"></i> Data tracking tidak valid.</div>';
    }
    
    $message = $tracking['message'];
    $responses = $tracking['responses'];
    $is_expired = $tracking['is_expired'];
    $current_level = $tracking['current_level'];
    $message_status = $tracking['message_status'];
    
    // Status pesan utama - lebih kecil
    $status_color = [
        'Pending' => '#ffc107',
        'Diproses' => '#17a2b8',
        'Disetujui' => '#28a745',
        'Ditolak' => '#dc3545',
        'Selesai' => '#28a745',
        'expired' => '#dc3545'
    ];
    
    $status_text = [
        'Pending' => 'Menunggu',
        'Diproses' => 'Diproses',
        'Disetujui' => 'Disetujui',
        'Ditolak' => 'Ditolak',
        'Selesai' => 'Selesai',
        'expired' => 'Kadaluarsa'
    ];
    
    $current_status = $is_expired ? 'expired' : $message_status;
    $current_status_color = $status_color[$current_status] ?? '#6c757d';
    $current_status_text = $status_text[$current_status] ?? ucfirst(strtolower($current_status));
    
    // Format tanggal lebih ringkas
    $tanggal_kirim = date('d/m/Y H:i', strtotime($message['tanggal_pesan'] ?? $message['created_at']));
    $expired_at = !empty($message['expired_at']) ? date('d/m/Y H:i', strtotime($message['expired_at'])) : '-';
    $jenis_pesan = $message['jenis_pesan'] ?? 'Pesan';
    
    // Tampilan lebih ramping - padding dan margin dikurangi
    $html = '
    <div class="tracking-container" style="padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; color: white;">
        <!-- Header lebih kecil -->
        <div style="background: rgba(255,255,255,0.1); backdrop-filter: blur(10px); border-radius: 10px; padding: 12px; margin-bottom: 15px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                <h3 style="font-size: 16px; margin: 0; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-search-location" style="color: #ffd700; font-size: 14px;"></i>
                    #' . htmlspecialchars($message['reference_number']) . '
                </h3>
                <span style="padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; background: ' . $current_status_color . '">
                    ' . $current_status_text . '
                </span>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; font-size: 11px;">
                <div><i class="fas fa-user" style="width: 16px; color: #ffd700;"></i> ' . htmlspecialchars($message['pengirim_nama'] ?? 'Unknown') . '</div>
                <div><i class="fas fa-tag" style="width: 16px; color: #ffd700;"></i> ' . htmlspecialchars($jenis_pesan) . '</div>
                <div><i class="fas fa-calendar" style="width: 16px; color: #ffd700;"></i> ' . $tanggal_kirim . '</div>
                <div><i class="fas fa-hourglass-end" style="width: 16px; color: #ffd700;"></i> ' . $expired_at . '</div>
            </div>
        </div>
        
        <!-- Timeline - lebih rapat -->
        <div style="position: relative; padding: 5px 0;">';
    
    // Timeline items - ukuran lebih kecil
    $prev_completed = true;
    
    foreach ($responses as $level => $resp) {
        $is_completed = $resp['is_completed'];
        $is_current = ($level == $current_level && !$is_completed && !$is_expired);
        $is_locked = !$prev_completed || $resp['is_locked'];
        
        // Warna marker
        $marker_bg = '#ffffff';
        $marker_color = '#667eea';
        if ($is_completed) {
            $marker_bg = '#28a745';
            $marker_color = '#ffffff';
        } elseif ($is_current) {
            $marker_bg = '#ffc107';
            $marker_color = '#212529';
        } elseif ($is_locked) {
            $marker_bg = '#6c757d';
            $marker_color = '#ffffff';
        }
        
        // Status badge
        $status_badge = '';
        if ($is_completed) {
            $status_badge = '<span style="background: #28a745; padding: 2px 8px; border-radius: 12px; font-size: 10px;"><i class="fas fa-check-circle me-1"></i> Selesai</span>';
        } elseif ($is_expired) {
            $status_badge = '<span style="background: #dc3545; padding: 2px 8px; border-radius: 12px; font-size: 10px;"><i class="fas fa-hourglass-end me-1"></i> Kadaluarsa</span>';
        } elseif ($is_current) {
            $status_badge = '<span style="background: #ffc107; color: #212529; padding: 2px 8px; border-radius: 12px; font-size: 10px;"><i class="fas fa-spinner fa-pulse me-1"></i> Proses</span>';
        } elseif ($is_locked) {
            $status_badge = '<span style="background: #6c757d; padding: 2px 8px; border-radius: 12px; font-size: 10px;"><i class="fas fa-lock me-1"></i> Lock</span>';
        } else {
            $status_badge = '<span style="background: #6c757d; padding: 2px 8px; border-radius: 12px; font-size: 10px;"><i class="fas fa-clock me-1"></i> Tunggu</span>';
        }
        
        // Waktu
        $time_text = $resp['time'] ? date('d/m H:i', strtotime($resp['time'])) : '-';
        
        $html .= '
        <div style="display: flex; margin-bottom: 12px; position: relative;">
            <!-- Marker -->
            <div style="position: relative; width: 40px; display: flex; flex-direction: column; align-items: center;">
                <div style="width: 30px; height: 30px; border-radius: 50%; background: ' . $marker_bg . '; display: flex; align-items: center; justify-content: center; font-size: 12px; color: ' . $marker_color . '; z-index: 2; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">
                    <i class="' . $resp['icon'] . '"></i>
                </div>
                ' . ($level < 4 ? '<div style="position: absolute; top: 30px; width: 2px; height: 22px; background: rgba(255,255,255,0.3);"></div>' : '') . '
            </div>
            
            <!-- Content -->
            <div style="flex: 1; background: rgba(255,255,255,0.1); backdrop-filter: blur(10px); border-radius: 8px; padding: 8px 12px; margin-left: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                    <div>
                        <h4 style="font-size: 13px; margin: 0;">' . htmlspecialchars($resp['type']) . '</h4>
                        <p style="font-size: 11px; margin: 2px 0 0; opacity: 0.8;"><i class="fas fa-user-circle me-1" style="color: #ffd700;"></i>' . htmlspecialchars($resp['subtitle']) . '</p>
                    </div>
                    <div style="text-align: right;">
                        ' . $status_badge . '
                        <div style="font-size: 9px; opacity: 0.6; margin-top: 2px;"><i class="fas fa-clock me-1"></i>' . $time_text . '</div>
                    </div>
                </div>';
        
        // Content berdasarkan level
        if ($level == 1 && $resp['show_content'] && !empty($resp['content'])) {
            // Level 1: Tampilkan isi pesan
            $html .= '
                <div style="margin-top: 6px; padding: 6px 10px; background: rgba(255,255,255,0.05); border-radius: 6px; font-size: 11px; position: relative;">
                    <i class="fas fa-quote-left" style="position: absolute; top: 2px; left: 2px; font-size: 10px; opacity: 0.3;"></i>
                    <p style="margin: 0; line-height: 1.4;">' . nl2br(htmlspecialchars($resp['content'])) . '</p>
                </div>';
        }
        
        if ($level == 2 && $resp['show_content']) {
            // Level 2: Tampilkan respon guru
            if (!empty($resp['content'])) {
                $html .= '
                    <div style="margin-top: 6px; padding: 6px 10px; background: rgba(255,255,255,0.05); border-radius: 6px; font-size: 11px; position: relative;">
                        <i class="fas fa-reply" style="position: absolute; top: 2px; left: 2px; font-size: 10px; opacity: 0.3;"></i>
                        <p style="margin: 0; line-height: 1.4;">' . nl2br(htmlspecialchars($resp['content'])) . '</p>
                    </div>';
            }
            
            // Tampilkan keputusan akhir (gabungan dengan respon guru)
            if (!empty($resp['keputusan']['catatan'])) {
                $keputusan_status = $resp['keputusan']['status'] ?? 'Diproses';
                $keputusan_badge = '';
                if ($keputusan_status == 'Disetujui') {
                    $keputusan_badge = '<span style="background: #28a745; padding: 2px 6px; border-radius: 10px; font-size: 9px; margin-left: 5px;">✓ Disetujui</span>';
                } elseif ($keputusan_status == 'Ditolak') {
                    $keputusan_badge = '<span style="background: #dc3545; padding: 2px 6px; border-radius: 10px; font-size: 9px; margin-left: 5px;">✗ Ditolak</span>';
                }
                
                $html .= '
                    <div style="margin-top: 8px; padding: 6px 10px; background: rgba(255,255,255,0.1); border-left: 3px solid #ffd700; border-radius: 4px; font-size: 11px;">
                        <div style="display: flex; align-items: center; margin-bottom: 4px;">
                            <i class="fas fa-gavel" style="color: #ffd700; font-size: 10px; margin-right: 5px;"></i>
                            <strong>Keputusan Akhir</strong>' . $keputusan_badge . '
                        </div>
                        <p style="margin: 0; line-height: 1.4;">' . nl2br(htmlspecialchars($resp['keputusan']['catatan'])) . '</p>
                        <div style="font-size: 9px; opacity: 0.6; margin-top: 4px;">
                            <i class="fas fa-clock me-1"></i>' . date('d/m/Y H:i', strtotime($resp['keputusan']['waktu'])) . '
                        </div>
                    </div>';
            }
        }
        
        // Level 3 dan 4: Tidak menampilkan content (hanya nama dan waktu)
        // Sudah cukup dengan subtitle dan waktu yang ditampilkan di atas
        
        $html .= '
            </div>
        </div>';
        
        $prev_completed = $is_completed;
    }
    
    // Legend - lebih kecil dan tanpa auto-refresh
    $html .= '
        </div>
        
        <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.2); display: flex; justify-content: center; gap: 15px; flex-wrap: wrap;">
            <span style="display: flex; align-items: center; gap: 4px; font-size: 10px;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #28a745;"></span> Selesai</span>
            <span style="display: flex; align-items: center; gap: 4px; font-size: 10px;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #ffc107;"></span> Proses</span>
            <span style="display: flex; align-items: center; gap: 4px; font-size: 10px;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #6c757d;"></span> Tunggu</span>
            <span style="display: flex; align-items: center; gap: 4px; font-size: 10px;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #dc3545;"></span> Lock/Kadaluarsa</span>
        </div>
    </div>';
    
    return $html;
}

// ============================================================
// PROSES LACAK PESAN
// ============================================================
$tracking_result = '';
$tracking_reference = isset($_POST['tracking_reference']) ? trim($_POST['tracking_reference']) : '';

// Jika ada parameter ref di URL (misalnya dari notifikasi email)
if (isset($_GET['ref']) && empty($tracking_reference)) {
    $tracking_reference = trim($_GET['ref']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['track_message']) && $_POST['track_message'] === '1') {
    if (!empty($tracking_reference)) {
        debug_log("========== MEMPROSES TRACKING ==========");
        debug_log("REFERENCE DARI INPUT: '" . $tracking_reference . "'");
        
        // Hanya trim, tidak perlu regex
        $tracking_reference = trim($tracking_reference);
        
        debug_log("Setelah trim: '" . $tracking_reference . "'");
        
        $tracking_data = getMessageTracking($db, $tracking_reference);
        
        // Cek tipe return dari getMessageTracking
        if (is_array($tracking_data) && isset($tracking_data['message'])) {
            // Tracking berhasil dengan data pesan
            $tracking_result = renderTrackingVisualization($tracking_data);
            debug_log("✓ TRACKING BERHASIL untuk: " . $tracking_reference);
        } elseif (is_array($tracking_data) && isset($tracking_data['error'])) {
            // Tracking gagal dengan pesan error spesifik
            $tracking_result = renderTrackingVisualization($tracking_data);
            debug_log("✗ TRACKING GAGAL: " . $tracking_data['message']);
        } else {
            // Tracking gagal dengan error tidak dikenal
            $tracking_result = '<div class="alert alert-danger py-2 small">
                <i class="fas fa-exclamation-circle me-1"></i> 
                Terjadi kesalahan yang tidak diketahui.
            </div>';
            debug_log("✗ TRACKING GAGAL - error tidak dikenal");
        }
    } else {
        $tracking_result = '<div class="alert alert-danger py-2 small">
            <i class="fas fa-times-circle me-1"></i> Harap masukkan nomor referensi.
        </div>';
    }
}

// Tampilkan hasil tracking jika ada
if (!empty($tracking_reference) && empty($tracking_result) && !isset($_POST['track_message'])) {
    // Auto-track jika ada parameter ref di URL
    $tracking_data = getMessageTracking($db, $tracking_reference);
    if (is_array($tracking_data) && isset($tracking_data['message'])) {
        $tracking_result = renderTrackingVisualization($tracking_data);
    }
}

// ============================================================
// PROSES KIRIM PESAN TANPA LOGIN (STEP 5,6,7 LENGKAP) + UPLOAD GAMBAR
// ============================================================
$message = '';
$error = '';

if (!isset($_SESSION['processed_messages'])) {
    $_SESSION['processed_messages'] = [];
}

foreach ($_SESSION['processed_messages'] as $key => $timestamp) {
    if (time() - $timestamp > 3600) {
        unset($_SESSION['processed_messages'][$key]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_external_message']) && $_POST['submit_external_message'] === '1') {
    
    debug_step("=" . str_repeat("=", 70), null, 'separator');
    debug_step("FORM SUBMIT DETECTED - KIRIM PESAN TANPA LOGIN", [
        'post_data' => array_diff_key($_POST, ['isi_pesan' => '']),
        'files' => $_FILES ? 'Ada file' : 'Tidak ada file',
        'server_time' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'],
        'method' => $_SERVER['REQUEST_METHOD']
    ], 'submit');
    
    $form_unique_id = $_POST['form_unique_id'] ?? '';
    $submit_token = md5($form_unique_id . $_SERVER['REMOTE_ADDR']);
    
    if (isset($_SESSION['processed_messages'][$submit_token])) {
        debug_step("✗ DUPLICATE SUBMIT DETECTED", [
            'token' => $submit_token,
            'waktu' => date('Y-m-d H:i:s', $_SESSION['processed_messages'][$submit_token])
        ], 'error');
        $error = "Form ini sudah diproses. Silakan refresh halaman untuk mengirim pesan baru.";
    } else {
        $_SESSION['processed_messages'][$submit_token] = time();
        $_SESSION['form_unique_id'] = bin2hex(random_bytes(16));
        
        try {
            if (!isset($db) || !$db->getConnection()) {
                $db = Database::getInstance();
                if (!$db || !$db->getConnection()) {
                    throw new Exception("Koneksi database tidak tersedia");
                }
            }
            debug_step("✓ STEP 1: Database siap digunakan");
            
            $nama = trim($_POST['nama_pengirim'] ?? '');
            $email = !empty($_POST['email_pengirim']) ? trim($_POST['email_pengirim']) : null;
            $phone_raw = $_POST['nomor_hp'] ?? '';
            $phone = !empty($phone_raw) ? preg_replace('/[^0-9]/', '', trim($phone_raw)) : null;
            $identitas = trim($_POST['identitas'] ?? '');
            $jenis_pesan_id = isset($_POST['jenis_pesan_id']) ? intval($_POST['jenis_pesan_id']) : 0;
            $prioritas = $_POST['prioritas'] ?? 'Medium';
            $isi_pesan = trim($_POST['isi_pesan'] ?? '');
            $captcha = isset($_POST['captcha']) ? true : false;
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $pesan_hash = md5($isi_pesan);
            
            debug_step("Data setelah dibersihkan", [
                'nama' => $nama,
                'email' => $email,
                'phone_clean' => $phone,
                'identitas' => $identitas,
                'jenis_pesan_id' => $jenis_pesan_id,
                'prioritas' => $prioritas,
                'isi_pesan_length' => strlen($isi_pesan),
                'pesan_hash' => $pesan_hash,
                'captcha' => $captcha ? 'checked' : 'unchecked',
                'ip_address' => $ip_address
            ]);
            
            $errors = [];
            if (empty($nama)) $errors[] = "Nama harus diisi";
            if (strlen($nama) < 3) $errors[] = "Nama minimal 3 karakter";
            if (strlen($nama) > 100) $errors[] = "Nama maksimal 100 karakter";
            
            if (empty($identitas)) $errors[] = "Identitas harus dipilih";
            if ($jenis_pesan_id <= 0) $errors[] = "Jenis pesan harus dipilih";
            
            if (empty($isi_pesan)) $errors[] = "Isi pesan harus diisi";
            if (strlen($isi_pesan) < 10) $errors[] = "Isi pesan minimal 10 karakter";
            if (strlen($isi_pesan) > 1000) $errors[] = "Isi pesan maksimal 1000 karakter";
            
            if (!$captcha) $errors[] = "Harap centang 'Saya bukan robot'";
            
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Format email tidak valid";
            }
            
            if (!empty($phone) && (strlen($phone) < 10 || strlen($phone) > 15)) {
                $errors[] = "Nomor HP harus 10-15 digit";
            }
            
            debug_step("Hasil validasi", [
                'error_count' => count($errors),
                'errors' => $errors,
                'is_valid' => empty($errors)
            ]);
            
            if (!empty($errors)) {
                throw new Exception(implode("<br>", $errors));
            }
            
            debug_step("✓ STEP 3: Validasi berhasil");
            
            $db->beginTransaction();
            debug_step("✓ STEP 4: Transaction started");
            
            // ====================================================
            // STEP 5: CEK DUPLIKAT PESAN (LENGKAP)
            // ====================================================
            debug_step("=" . str_repeat("-", 50), null, 'separator');
            debug_step("STEP 5: CEK DUPLIKAT PESAN");
            
            $conditions = [];
            $params = [];
            
            if (!empty($email)) {
                $conditions[] = "(pengirim_email = :email_cek)";
                $params[':email_cek'] = $email;
            }
            
            if (!empty($phone)) {
                $conditions[] = "(pengirim_phone = :phone_cek)";
                $params[':phone_cek'] = $phone;
            }
            
            if (empty($email) && empty($phone)) {
                $conditions[] = "(ip_address = :ip_cek)";
                $params[':ip_cek'] = $ip_address;
            }
            
            if (!empty($conditions)) {
                $condition_str = implode(' OR ', $conditions);
                
                $sql_cek = "SELECT id, reference_number, created_at, isi_pesan 
                           FROM messages 
                           WHERE ($condition_str) 
                           AND DATE(created_at) = CURDATE() 
                           AND isi_pesan = :isi_pesan
                           AND is_external = 1
                           ORDER BY created_at DESC LIMIT 1";
                
                $params[':isi_pesan'] = $isi_pesan;
                
                debug_step("STEP 5.1: Menjalankan query cek duplikat", [
                    'sql' => $sql_cek,
                    'params' => $params
                ]);
                
                $cek_duplicate = $db->select($sql_cek, $params);
                
                if (!empty($cek_duplicate)) {
                    debug_step("⚠️ STEP 5.2: DUPLIKAT PESAN DITEMUKAN!", [
                        'existing_id' => $cek_duplicate[0]['id'],
                        'reference' => $cek_duplicate[0]['reference_number']
                    ], 'warning');
                    
                    throw new Exception("Anda sudah mengirim pesan yang sama hari ini.");
                } else {
                    debug_step("✓ STEP 5.2: Tidak ada duplikat pesan");
                }
            }
            
            // ====================================================
            // STEP 6: EXTERNAL SENDERS (LENGKAP)
            // ====================================================
            debug_step("=" . str_repeat("-", 50), null, 'separator');
            debug_step("STEP 6: PROSES EXTERNAL SENDERS - CEK KOMBINASI NAMA, EMAIL, PHONE");
            
            $external_sender_id = null;
            $external_action = '';
            
            // Bangun kondisi untuk mencari external sender yang EXACT SAMA
            $where_conditions = [];
            $query_params = [];
            
            // Nama harus sama persis
            $where_conditions[] = "nama_lengkap = :nama_cek";
            $query_params[':nama_cek'] = $nama;
            
            // Email - jika diisi harus sama, jika kosong harus NULL
            if (!empty($email)) {
                $where_conditions[] = "email = :email_cek";
                $query_params[':email_cek'] = $email;
            } else {
                $where_conditions[] = "email IS NULL";
            }
            
            // Phone - jika diisi harus sama, jika kosong harus NULL
            if (!empty($phone)) {
                $where_conditions[] = "phone_number = :phone_cek";
                $query_params[':phone_cek'] = $phone;
            } else {
                $where_conditions[] = "phone_number IS NULL";
            }
            
            $where_clause = implode(' AND ', $where_conditions);
            
            $sql = "SELECT id, nama_lengkap, email, phone_number, identitas 
                    FROM external_senders 
                    WHERE $where_clause 
                    LIMIT 1";
            
            debug_step("STEP 6.1: Mencari external sender dengan kombinasi exact", [
                'sql' => $sql,
                'params' => $query_params,
                'kombinasi' => "Nama: $nama, Email: " . ($email ?: 'NULL') . ", Phone: " . ($phone ?: 'NULL')
            ]);
            
            try {
                $result = $db->select($sql, $query_params);
                
                if (!empty($result)) {
                    $external_sender_id = $result[0]['id'];
                    debug_step("✓ STEP 6.2: EXTERNAL SENDER DITEMUKAN DENGAN KOMBINASI EXACT - ID: " . $external_sender_id);
                    
                    // UPDATE data yang mungkin berubah (identitas saja yang bisa update)
                    $update_fields = [];
                    $update_params = [':id' => $external_sender_id];
                    
                    if ($result[0]['identitas'] !== $identitas) {
                        $update_fields[] = "identitas = :identitas";
                        $update_params[':identitas'] = $identitas;
                    }
                    
                    if (!empty($update_fields)) {
                        $update_fields[] = "updated_at = NOW()";
                        $sql_update = "UPDATE external_senders SET " . implode(', ', $update_fields) . " WHERE id = :id";
                        
                        debug_step("STEP 6.3: Menjalankan UPDATE identitas", [
                            'sql' => $sql_update,
                            'params' => $update_params
                        ]);
                        
                        $update_result = $db->execute($sql_update, $update_params);
                        
                        if ($update_result) {
                            debug_step("✓ STEP 6.4: UPDATE BERHASIL");
                            $external_action = "UPDATE (ID: $external_sender_id) - Identitas diperbarui";
                        }
                    } else {
                        debug_step("✓ STEP 6.3: TIDAK ADA PERUBAHAN, menggunakan existing");
                        $external_action = "EXISTING (ID: $external_sender_id) - Tidak ada perubahan";
                    }
                } else {
                    debug_step("STEP 6.2: TIDAK DITEMUKAN kombinasi exact, akan INSERT baru");
                    
                    // INSERT EXTERNAL SENDER BARU
                    $unique_hash = md5($nama . ($email ?? '') . ($phone ?? '') . time() . rand(1000, 9999));
                    
                    $sql_insert = "INSERT INTO external_senders (
                                    nama_lengkap, email, phone_number, identitas, 
                                    unique_hash, is_verified, created_at, updated_at
                                  ) VALUES (
                                    :nama, :email, :phone, :identitas, 
                                    :hash, 0, NOW(), NOW()
                                  )";
                    
                    $params_insert = [
                        ':nama' => $nama,
                        ':email' => $email,
                        ':phone' => $phone,
                        ':identitas' => $identitas,
                        ':hash' => $unique_hash
                    ];
                    
                    debug_step("STEP 6.5: Menjalankan INSERT", [
                        'sql' => $sql_insert,
                        'params' => $params_insert
                    ]);
                    
                    $insert_result = $db->execute($sql_insert, $params_insert);
                    
                    if ($insert_result) {
                        $external_sender_id = $db->lastInsertId();
                        debug_step("✓ STEP 6.6: INSERT BERHASIL", ['external_sender_id' => $external_sender_id]);
                        $external_action = "INSERT NEW (ID: $external_sender_id)";
                    } else {
                        $error_info = $db->errorInfo();
                        throw new Exception("Gagal insert external sender: " . ($error_info[2] ?? 'Unknown error'));
                    }
                }
            } catch (Exception $e) {
                debug_step("✗ STEP 6: ERROR saat cek external sender", [
                    'error' => $e->getMessage()
                ], 'error');
                throw $e;
            }
            
            debug_step("✓ STEP 6: EXTERNAL SENDERS SELESAI", [
                'external_sender_id' => $external_sender_id,
                'action' => $external_action
            ]);
            
            // ====================================================
            // STEP 7: USERS (LENGKAP)
            // ====================================================
            debug_step("=" . str_repeat("-", 50), null, 'separator');
            debug_step("STEP 7: PROSES USERS - CEK KOMBINASI NAMA, EMAIL, PHONE");
            
            $pengirim_id = null;
            $user_action = '';
            
            // Hanya cek users jika ada email atau phone
            if (!empty($email) || !empty($phone)) {
                // Bangun kondisi untuk mencari user yang EXACT SAMA
                $where_conditions = [];
                $query_params = [];
                
                // Nama harus sama persis
                $where_conditions[] = "nama_lengkap = :nama_cek";
                $query_params[':nama_cek'] = $nama;
                
                // Email - jika diisi harus sama, jika kosong harus NULL
                if (!empty($email)) {
                    $where_conditions[] = "email = :email_cek";
                    $query_params[':email_cek'] = $email;
                } else {
                    $where_conditions[] = "email IS NULL";
                }
                
                // Phone - jika diisi harus sama, jika kosong harus NULL
                if (!empty($phone)) {
                    $where_conditions[] = "phone_number = :phone_cek";
                    $query_params[':phone_cek'] = $phone;
                } else {
                    $where_conditions[] = "phone_number IS NULL";
                }
                
                $where_clause = implode(' AND ', $where_conditions);
                
                $sql = "SELECT id, user_type, nama_lengkap, email, phone_number 
                        FROM users 
                        WHERE $where_clause 
                        LIMIT 1";
                
                debug_step("STEP 7.1: Mencari user dengan kombinasi exact", [
                    'sql' => $sql,
                    'params' => $query_params,
                    'kombinasi' => "Nama: $nama, Email: " . ($email ?: 'NULL') . ", Phone: " . ($phone ?: 'NULL')
                ]);
                
                try {
                    $result = $db->select($sql, $query_params);
                    
                    if (!empty($result)) {
                        $pengirim_id = $result[0]['id'];
                        debug_step("✓ STEP 7.2: USER DITEMUKAN DENGAN KOMBINASI EXACT - ID: " . $pengirim_id);
                        
                        // Update user jika perlu (hanya update jika ada field yang berubah)
                        $updates = [];
                        $params_update = [':id' => $pengirim_id];
                        
                        // Pastikan user_type External
                        if ($result[0]['user_type'] !== 'External') {
                            $updates[] = "user_type = 'External'";
                        }
                        
                        if (!empty($updates)) {
                            $updates[] = "updated_at = NOW()";
                            $sql_update = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id";
                            
                            $db->execute($sql_update, $params_update);
                            debug_step("✓ STEP 7.3: UPDATE USER BERHASIL (user_type)");
                        }
                        
                        $user_action = "EXISTING (ID: $pengirim_id) - Tidak ada perubahan data";
                    } else {
                        debug_step("STEP 7.2: TIDAK DITEMUKAN kombinasi exact, akan INSERT baru");
                        
                        // INSERT USER BARU
                        $username = 'ext_' . time() . rand(1000, 9999);
                        $random_password = bin2hex(random_bytes(8));
                        $password_hash = password_hash($random_password, PASSWORD_DEFAULT);
                        
                        $sql_insert = "INSERT INTO users (
                                        username, password_hash, email, user_type,
                                        nama_lengkap, phone_number, privilege_level,
                                        is_active, avatar, created_at, updated_at
                                      ) VALUES (
                                        :username, :password_hash, :email, 'External',
                                        :nama, :phone, 'Limited_Lv3',
                                        1, 'default-avatar.png', NOW(), NOW()
                                      )";
                        
                        $params_insert = [
                            ':username' => $username,
                            ':password_hash' => $password_hash,
                            ':email' => $email,
                            ':nama' => $nama,
                            ':phone' => $phone
                        ];
                        
                        debug_step("STEP 7.4: Menjalankan INSERT user baru", [
                            'sql' => $sql_insert,
                            'params' => $params_insert
                        ]);
                        
                        $insert_result = $db->execute($sql_insert, $params_insert);
                        
                        if ($insert_result) {
                            $pengirim_id = $db->lastInsertId();
                            debug_step("✓ STEP 7.5: INSERT USER BERHASIL", ['pengirim_id' => $pengirim_id]);
                            $user_action = "INSERT NEW (ID: $pengirim_id)";
                        } else {
                            $error_info = $db->errorInfo();
                            throw new Exception("Gagal insert user: " . ($error_info[2] ?? 'Unknown error'));
                        }
                    }
                } catch (Exception $e) {
                    debug_step("✗ STEP 7: ERROR saat cek user", ['error' => $e->getMessage()], 'error');
                    throw $e;
                }
            } else {
                debug_step("STEP 7: Tidak ada email dan no hp, lewati proses users");
            }
            
            debug_step("✓ STEP 7: USERS SELESAI", [
                'pengirim_id' => $pengirim_id ?: 'NULL (tidak dibuat)',
                'action' => $user_action ?: 'SKIP (tidak ada email/phone)'
            ]);
            
            // ====================================================
            // STEP 8: GENERATE REFERENCE NUMBER
            // ====================================================
            $reference = 'EXT' . date('Ymd') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $attempt = 1;
            
            do {
                $cek_ref = $db->select("SELECT id FROM messages WHERE reference_number = :ref LIMIT 1", [':ref' => $reference]);
                if (!empty($cek_ref)) {
                    $reference = 'EXT' . date('Ymd') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
                    $attempt++;
                }
            } while (!empty($cek_ref) && $attempt < 10);
            
            debug_step("✓ STEP 8: Reference number", ['reference' => $reference]);
            
            // ====================================================
            // STEP 9: HITUNG EXPIRED AT
            // ====================================================
            $deadline_hours = 72;
            try {
                $result = $db->select("SELECT response_deadline_hours FROM message_types WHERE id = :id", [':id' => $jenis_pesan_id]);
                if (!empty($result)) {
                    $deadline_hours = $result[0]['response_deadline_hours'];
                }
            } catch (Exception $e) {
                debug_step("Gagal ambil deadline, pakai default", ['error' => $e->getMessage()]);
            }
            
            $expired_at = date('Y-m-d H:i:s', strtotime('+' . $deadline_hours . ' hours'));
            debug_step("✓ STEP 9: Expired at", ['expired_at' => $expired_at]);
            
            // ====================================================
            // STEP 10: INSERT MESSAGES
            // ====================================================
            debug_step("=" . str_repeat("-", 50), null, 'separator');
            debug_step("STEP 10: INSERT MESSAGES");
            
            $sql_insert = "INSERT INTO messages (
                            reference_number, tanggal_pesan, jenis_pesan_id,
                            pengirim_id, external_sender_id,
                            pengirim_nama, pengirim_email, pengirim_phone,
                            isi_pesan, status, priority, is_external,
                            ip_address, user_agent, submission_channel,
                            expired_at, followup_count,
                            email_notified, whatsapp_notified, has_attachments,
                            created_at, updated_at
                          ) VALUES (
                            :ref, NOW(), :jenis,
                            :pengirim_id, :external_sender_id,
                            :nama, :email, :phone,
                            :isi, 'Pending', :priority, 1,
                            :ip, :ua, 'web',
                            :expired, 0,
                            0, 0, 0,
                            NOW(), NOW()
                          )";
            
            $params_insert = [
                ':ref' => $reference,
                ':jenis' => $jenis_pesan_id,
                ':pengirim_id' => $pengirim_id,
                ':external_sender_id' => $external_sender_id,
                ':nama' => $nama,
                ':email' => $email,
                ':phone' => $phone,
                ':isi' => $isi_pesan,
                ':priority' => $prioritas,
                ':ip' => $ip_address,
                ':ua' => $user_agent,
                ':expired' => $expired_at
            ];
            
            debug_step("STEP 10.1: Parameter insert messages", $params_insert);
            
            $insert_result = $db->execute($sql_insert, $params_insert);
            
            if ($insert_result) {
                $message_id = $db->lastInsertId();
                debug_step("✓✓✓ STEP 10.2: INSERT MESSAGES BERHASIL", [
                    'message_id' => $message_id,
                    'reference' => $reference
                ]);
                
                // ====================================================
                // STEP 10.5: HANDLE FILE UPLOAD (OPSIONAL) - SESUAI STRUKTUR message_attachments
                // PERBAIKAN: Menggunakan method execute() bukan prepare()
                // PERBAIKAN: Menghilangkan nilai id dari INSERT (auto_increment)
                // PERBAIKAN: user_id menggunakan nilai aman (bukan 0)
                // ====================================================
                $uploaded_files = [];
                $has_attachments = 0;
                
                if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
                    debug_step(">>> MEMPROSES UPLOAD GAMBAR");
                    upload_log(">>> MEMPROSES UPLOAD GAMBAR untuk message_id: $message_id, reference: $reference, pengirim_id: " . ($pengirim_id ?: 'NULL'));
                    
                    $total_files = count($_FILES['attachments']['name']);
                    debug_step("Total files: " . $total_files);
                    upload_log("Total files: " . $total_files);
                    
                    for ($i = 0; $i < $total_files; $i++) {
                        $file = [
                            'name' => $_FILES['attachments']['name'][$i],
                            'type' => $_FILES['attachments']['type'][$i],
                            'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                            'error' => $_FILES['attachments']['error'][$i],
                            'size' => $_FILES['attachments']['size'][$i]
                        ];
                        
                        $upload_result = handleExternalImageUpload($file, $reference, $external_sender_id, $pengirim_id);
                        
                        if ($upload_result['success'] && isset($upload_result['uploaded']) && $upload_result['uploaded']) {
                            // Pastikan user_id tidak null dan tidak 0 jika memungkinkan
                            $safe_user_id = ($pengirim_id && $pengirim_id > 0) ? $pengirim_id : 1; // Gunakan 1 sebagai default jika tidak ada
                            
                            // Simpan ke database sesuai struktur tabel message_attachments - MENGGUNAKAN METHOD EXECUTE()
                            // TIDAK menyertakan id karena auto_increment
                            $attach_sql = "
                                INSERT INTO message_attachments (
                                    message_id, user_id, filename, filepath, 
                                    filetype, filesize, is_approved, virus_scan_status, 
                                    download_count, created_at
                                ) VALUES (
                                    :message_id, :user_id, :filename, :filepath, 
                                    :filetype, :filesize, 1, 'Pending', 
                                    0, NOW()
                                )
                            ";
                            
                            $attachParams = [
                                ':message_id' => $message_id,
                                ':user_id' => $safe_user_id, // Gunakan nilai aman, bukan 0
                                ':filename' => $upload_result['filename'],
                                ':filepath' => $upload_result['filepath'],
                                ':filetype' => $upload_result['filetype'],
                                ':filesize' => $upload_result['filesize']
                            ];
                            
                            debug_step("Parameter insert attachment", $attachParams);
                            upload_log("Parameter insert attachment", $attachParams);
                            
                            // Gunakan method execute() dari class Database, bukan prepare()
                            $attachResult = $db->execute($attach_sql, $attachParams);
                            
                            if ($attachResult) {
                                // Ambil ID terakhir yang diinsert menggunakan query terpisah
                                $last_id_sql = "SELECT LAST_INSERT_ID() as last_id";
                                $last_id_result = $db->select($last_id_sql);
                                $attachment_id = $last_id_result[0]['last_id'] ?? 0;
                                
                                $uploaded_files[] = array_merge($upload_result, ['attachment_id' => $attachment_id]);
                                $has_attachments = 1;
                                debug_step("✓ Data attachment disimpan ke database", [
                                    'attachment_id' => $attachment_id,
                                    'filename' => $upload_result['filename']
                                ]);
                                upload_log("✓ Data attachment disimpan ke database", [
                                    'attachment_id' => $attachment_id,
                                    'filename' => $upload_result['filename']
                                ]);
                            } else {
                                $error_info = $db->errorInfo();
                                debug_step("✗ Gagal menyimpan data attachment ke database: " . ($error_info[2] ?? 'Unknown error'));
                                upload_log("✗ Gagal menyimpan data attachment ke database", $error_info);
                            }
                        } elseif (!$upload_result['success']) {
                            debug_step("✗ Gagal upload file ke-$i: " . ($upload_result['error'] ?? 'Unknown error'));
                            upload_log("✗ Gagal upload file ke-$i: " . ($upload_result['error'] ?? 'Unknown error'));
                            // Jangan batalkan transaksi, tetap lanjutkan karena upload opsional
                        }
                    }
                    
                    // Update has_attachments jika ada file yang berhasil diupload
                    if ($has_attachments) {
                        $update_sql = "UPDATE messages SET has_attachments = 1 WHERE id = ?";
                        $db->execute($update_sql, [$message_id]);
                        debug_step("✓ has_attachments diupdate menjadi 1");
                        upload_log("✓ has_attachments diupdate menjadi 1");
                        
                        // Simpan ke session untuk ditampilkan di email
                        $_SESSION['uploaded_files'] = $uploaded_files;
                    }
                } else {
                    debug_step("Tidak ada file yang diupload (opsional)");
                    upload_log("Tidak ada file yang diupload (opsional)");
                }
                
                // ====================================================
                // STEP 11: AMBIL NAMA JENIS PESAN
                // ====================================================
                $jenis_pesan_name = getJenisPesanName($db, $jenis_pesan_id);
                debug_step("Jenis pesan: " . $jenis_pesan_name);
                
                // ====================================================
                // STEP 12: KIRIM NOTIFIKASI DENGAN KONFIGURASI TERPUSAT
                // ====================================================
                debug_step("=" . str_repeat("-", 50), null, 'separator');
                debug_step("STEP 12: MENGIRIM NOTIFIKASI");
                
                $email_terkirim = false;
                $wa_terkirim = false;
                $email_error = '';
                $wa_error = '';
                
                // Kirim Email jika ada alamat email
                if (!empty($email)) {
                    debug_log(">>> MENGIRIM EMAIL NOTIFIKASI ke: " . $email);
                    $email_result = kirimEmail($email, $nama, $reference, $isi_pesan, $jenis_pesan_name, $prioritas);
                    $email_terkirim = $email_result['success'] ?? false;
                    
                    if ($email_terkirim) {
                        debug_log("✓ EMAIL NOTIFIKASI BERHASIL");
                        $db->execute("UPDATE messages SET email_notified = 1 WHERE id = ?", [$message_id]);
                    } else {
                        $email_error = $email_result['error'] ?? 'Email gagal';
                        debug_log("✗ EMAIL NOTIFIKASI GAGAL: " . $email_error);
                    }
                }
                
                // Kirim WhatsApp jika ada nomor HP
                if (!empty($phone)) {
                    debug_log(">>> MENGIRIM WHATSAPP NOTIFIKASI ke: " . $phone);
                    $wa_result = kirimWhatsApp($phone, $nama, $reference, $isi_pesan, $jenis_pesan_name, $prioritas);
                    $wa_terkirim = $wa_result['success'] ?? false;
                    
                    if ($wa_terkirim) {
                        debug_log("✓ WHATSAPP NOTIFIKASI BERHASIL");
                        $db->execute("UPDATE messages SET whatsapp_notified = 1 WHERE id = ?", [$message_id]);
                    } else {
                        $wa_error = $wa_result['error'] ?? 'WhatsApp gagal';
                        debug_log("✗ WHATSAPP NOTIFIKASI GAGAL: " . $wa_error);
                    }
                }
                
                // Simpan status notifikasi di session
                $_SESSION['message_notifications'] = [
                    'email' => $email_terkirim,
                    'whatsapp' => $wa_terkirim,
                    'email_error' => $email_error,
                    'wa_error' => $wa_error,
                    'reference' => $reference
                ];
                
                // Hapus session uploaded_files setelah digunakan
                if (isset($_SESSION['uploaded_files'])) {
                    unset($_SESSION['uploaded_files']);
                }
                
                debug_step("STATUS NOTIFIKASI - Email: " . ($email_terkirim ? 'OK' : 'Gagal') . ", WA: " . ($wa_terkirim ? 'OK' : 'Gagal'));
                
            } else {
                $error_info = $db->errorInfo();
                throw new Exception("Gagal insert messages: " . ($error_info[2] ?? 'Unknown error'));
            }
            
            $db->commit();
            debug_step("✓✓✓ STEP 13: TRANSACTION COMMITTED");
            
            // TAMPILKAN SUKSES
            $message = "<div style='background: #d4edda; border: 2px solid #28a745; color: #155724; padding: 25px; border-radius: 10px; margin-bottom: 25px;'>";
            $message .= "<h3 style='margin-top:0;'><i class='fas fa-check-circle'></i> PESAN BERHASIL DIKIRIM!</h3>";
            $message .= "<p><strong>Nomor Referensi:</strong> <span style='background: #155724; color: white; padding: 5px 15px; border-radius: 5px;'>{$reference}</span></p>";
            $message .= "<p><strong>Jenis Pesan:</strong> {$jenis_pesan_name}</p>";
            
            // Tampilkan info upload jika ada
            if (!empty($uploaded_files)) {
                $message .= "<hr style='border-top:1px solid #28a745;'>";
                $message .= "<p><strong>📎 Lampiran:</strong> " . count($uploaded_files) . " file berhasil diupload:</p>";
                $message .= "<ul class='small'>";
                foreach ($uploaded_files as $file) {
                    $message .= "<li><i class='fas fa-image text-success'></i> " . htmlspecialchars($file['original_name']) . " (" . round($file['filesize'] / 1024, 1) . " KB)</li>";
                }
                $message .= "</ul>";
            }
            
            if (!empty($email) || !empty($phone)) {
                $message .= "<hr style='border-top:1px solid #28a745;'>";
                $message .= "<p><strong>Notifikasi:</strong></p>";
                if (!empty($email)) {
                    $message .= "<p>📧 Email: " . ($email_terkirim ? "✓ Terkirim ke $email" : "✗ Gagal - $email_error") . "</p>";
                }
                if (!empty($phone)) {
                    $message .= "<p>📱 WhatsApp: " . ($wa_terkirim ? "✓ Terkirim ke $phone" : "✗ Gagal - $wa_error") . "</p>";
                }
            }
            
            $message .= "<hr style='border-top:1px solid #28a745;'>";
            $message .= "<p>✓ External Sender ID: {$external_sender_id} ({$external_action})</p>";
            $message .= "<p>✓ User ID: " . ($pengirim_id ?: 'Tidak dibuat') . " (" . ($user_action ?: 'SKIP') . ")</p>";
            $message .= "</div>";
            
            $_POST = [];
            
        } catch (Exception $e) {
            if (isset($db) && method_exists($db, 'rollBack')) {
                $db->rollBack();
                debug_step("✓ ROLLBACK: Transaction dibatalkan");
            }
            
            debug_step("✗✗✗ ERROR: " . $e->getMessage(), null, 'error');
            
            $error = "<div style='background: #f8d7da; border: 2px solid #dc3545; color: #721c24; padding: 25px; border-radius: 10px; margin-bottom: 25px;'>";
            $error .= "<h3 style='margin-top:0;'><i class='fas fa-exclamation-triangle'></i> ERROR</h3>";
            $error .= "<p>{$e->getMessage()}</p>";
            $error .= "</div>";
        }
    }
}

// ============================================================
// FUNGSI UNTUK RESET FORM
// ============================================================
if (isset($_GET['reset_form'])) {
    $_POST = [];
    $message = '';
    $error = '';
    $tracking_result = '';
    header('Location: index.php');
    exit;
}

// Cek status login
$user_logged_in = isset($_SESSION['user_id']);

// ============================================================
// TAMPILAN HTML (bagian tracking section sudah diperkecil)
// ============================================================
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMKN 12 Jakarta - Responsive Message App</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Dark Mode CSS -->
    <link rel="stylesheet" href="/assets/css/dark-mode.css">
    <!-- QR Code Generator Library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <style>
        /* CSS yang sudah ada - tidak diubah */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(145deg, #0b4d8a 0%, #1a73e8 50%, #4285f4 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .container { max-width: 1300px; margin: 0 auto; }
        
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
            padding: 20px 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .logo-area {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .logo {
            width: 80px;
            height: 80px;
            border-radius: 15px;
            background: linear-gradient(145deg, #0b4d8a, #1a73e8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
        }
        .logo-text h1 {
            color: #0b4d8a;
            font-size: 28px;
            margin-bottom: 5px;
        }
        .auth-buttons { display: flex; gap: 15px; }
        .btn-auth {
            padding: 12px 25px;
            border: none;
            border-radius: 50px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-login { background: #0b4d8a; color: white; }
        .btn-register { background: #28a745; color: white; }
        .btn-logout { background: #dc3545; color: white; }
        
        .badge-fonnte {
            background: linear-gradient(145deg, #25D366, #128C7E);
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-mailersend {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        /* Debug Panel */
        .debug-panel {
            background: #1e1e2f;
            border-radius: 10px;
            margin-bottom: 30px;
            color: #e0e0e0;
            font-family: 'Consolas', monospace;
            border: 1px solid #2d2d44;
            display: none;
        }
        .debug-panel.visible { display: block; }
        .debug-header {
            background: #2d2d44;
            padding: 15px 20px;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        .debug-content {
            padding: 20px;
            max-height: 500px;
            overflow-y: auto;
            display: none;
        }
        .debug-content.show { display: block; }
        .debug-entry {
            margin-bottom: 15px;
            padding: 12px;
            border-left: 4px solid;
            background: rgba(255,255,255,0.05);
            border-radius: 0 5px 5px 0;
            font-size: 13px;
        }
        .debug-entry.success { border-left-color: #28a745; }
        .debug-entry.error { border-left-color: #dc3545; }
        .debug-entry.warning { border-left-color: #ffc107; }
        .debug-step { color: #ff9900; font-weight: bold; margin-right: 10px; }
        .debug-time { color: #888; font-size: 11px; margin-left: 10px; }
        .debug-data {
            background: #000;
            padding: 12px;
            border-radius: 5px;
            margin-top: 8px;
            font-size: 12px;
            color: #0f0;
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
            padding: 12px 20px;
            font-size: 14px;
            cursor: pointer;
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Form */
        .card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            padding: 35px;
            margin-bottom: 25px;
        }
        .form-group { margin-bottom: 20px; }
        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .btn-primary {
            background: linear-gradient(145deg, #1a73e8, #0d47a1);
            color: white;
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            cursor: pointer;
        }
        .btn-secondary {
            background: linear-gradient(145deg, #6c757d, #495057);
            color: white;
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-secondary:hover {
            background: linear-gradient(145deg, #5a6268, #343a40);
        }
        .footer { text-align: center; color: white; margin-top: 30px; }
        
        /* Additional styles */
        optgroup {
            font-weight: bold;
            color: #0b4d8a;
        }
        .required:after {
            content: " *";
            color: red;
        }
        
        /* Hero section with QR Code */
        .hero-section {
            display: flex;
            align-items: center;
            gap: 30px;
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        .hero-content {
            flex: 2;
        }
        .qr-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 15px;
            background: linear-gradient(145deg, #f8f9fa, #e9ecef);
            border-radius: 15px;
            box-shadow: 0 15px 25px rgba(0,0,0,0.2);
            transform-style: preserve-3d;
            transition: transform 0.3s ease;
        }
        .qr-container:hover {
            transform: rotateY(10deg) rotateX(5deg);
        }
        #qrcode {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 10px 0;
            filter: drop-shadow(0 10px 15px rgba(0,0,0,0.3));
        }
        #qrcode canvas {
            border-radius: 10px;
            border: 5px solid white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        #qrcode canvas:hover {
            transform: scale(1.05);
            box-shadow: 0 15px 30px rgba(0,0,0,0.3);
        }
        .qr-label {
            text-align: center;
            margin-top: 10px;
            font-size: 14px;
            color: #495057;
            font-weight: 500;
            background: white;
            padding: 8px 15px;
            border-radius: 50px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .qr-label i {
            color: #0b4d8a;
            margin-right: 5px;
        }
        .hero-image {
            max-width: 100%;
            border-radius: 10px;
        }
        .hero-title {
            color: #0b4d8a;
            font-size: 28px;
            margin-bottom: 15px;
        }
        .hero-text {
            color: #6c757d;
            font-size: 16px;
            line-height: 1.6;
        }
        
        /* Button group */
        .button-group {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .refresh-btn {
            background: #6c757d;
            color: white;
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        .refresh-btn:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        /* Info Layanan */
        .service-info {
            background: linear-gradient(145deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #0b4d8a;
        }
        .service-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 8px;
        }
        .service-badge.mailersend {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        .service-badge.fonnte {
            background: linear-gradient(145deg, #25D366, #128C7E);
            color: white;
        }

        /* Dark Mode Toggle Button */
        .dark-mode-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            background: #2d2d2d;
            color: white;
            border: none;
            border-radius: 50px;
            padding: 12px 20px;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            transition: all 0.3s ease;
            border: 1px solid #404040;
        }
        .dark-mode-toggle:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.4);
            background: #383838;
        }
        .dark-mode-toggle i {
            font-size: 16px;
        }
        [data-theme="dark"] .dark-mode-toggle {
            background: var(--primary-color);
            border-color: var(--primary-dark);
        }
        [data-theme="dark"] .dark-mode-toggle:hover {
            background: var(--primary-dark);
        }
        
        /* ==================================================== */
        /* CSS UNTUK FITUR UPLOAD GAMBAR */
        /* ==================================================== */
        .upload-area {
            border: 2px dashed #0d6efd;
            background-color: #f8f9fa;
            transition: all 0.3s ease;
            cursor: pointer;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }
        
        .upload-area:hover {
            background-color: #e9ecef;
            border-color: #0a58ca;
        }
        
        .upload-area.dragover {
            background-color: #e2e6ea;
            border-color: #0a58ca;
        }
        
        .upload-icon {
            margin-bottom: 10px;
        }
        
        .upload-icon i {
            font-size: 40px;
            color: #0b4d8a;
        }
        
        .preview-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s;
            margin-bottom: 10px;
        }
        
        .preview-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .preview-item img {
            width: 100%;
            height: 100px;
            object-fit: cover;
        }
        
        .preview-item .preview-info {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 4px 8px;
            font-size: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .preview-item .remove-file {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(220,53,69,0.9);
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }
        
        .preview-item .remove-file:hover {
            background: #dc3545;
            transform: scale(1.1);
        }
        
        .file-size {
            font-size: 9px;
            opacity: 0.9;
        }
        
        /* ==================================================== */
        /* CSS UNTUK FITUR TRACKING PESAN - VERSION RAMPING */
        /* ==================================================== */
        .tracking-section {
            background: white;
            border-radius: 16px;
            box-shadow: 0 15px 30px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .tracking-section h2 {
            color: #0b4d8a;
            font-size: 20px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
        }
        
        .tracking-section h2 i {
            color: #1a73e8;
            font-size: 22px;
        }
        
        .tracking-input-group {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        .tracking-input {
            flex: 1;
        }
        
        .tracking-input label {
            display: block;
            margin-bottom: 5px;
            color: #495057;
            font-weight: 500;
            font-size: 13px;
        }
        
        .tracking-input .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 15px;
            font-size: 14px;
            height: 42px;
            transition: all 0.3s ease;
        }
        
        .tracking-input .form-control:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26,115,232,0.1);
            outline: none;
        }
        
        .btn-track {
            background: linear-gradient(145deg, #28a745, #218838);
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 42px;
        }
        
        .btn-track:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40,167,69,0.3);
        }
        
        .btn-track i {
            font-size: 16px;
        }
        
        .btn-secondary-sm {
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 42px;
        }
        
        .tracking-result {
            margin-top: 20px;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Alert lebih kecil */
        .alert {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: 13px;
        }
        
        .alert-warning {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
        }
        
        .alert-danger {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .tracking-input-group {
                flex-direction: column;
            }
            .btn-track {
                width: 100%;
            }
            .btn-secondary-sm {
                width: 100%;
            }
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- DARK MODE TOGGLE BUTTON -->
    <button class="dark-mode-toggle" onclick="toggleDarkMode()" id="darkModeToggle">
        <i class="fas fa-moon"></i> <span id="darkModeText">Dark Mode</span>
    </button>

    <!-- DEBUG TOGGLE BUTTON -->
    <div class="debug-toggle-btn" onclick="toggleDebugPanel()" id="debugToggleBtn">
        <i class="fas fa-bug"></i> Debug Mode
        <span style="background: #ff9900; color: #1e1e2f; border-radius: 20px; padding: 2px 10px;">
            <?php echo count($debug_steps); ?>
        </span>
    </div>

    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <div class="logo-area">
                <div class="logo"><i class="fas fa-comments"></i></div>
                <div class="logo-text">
                    <h1>SMKN 12 Jakarta</h1>
                    <p>Responsive Message App • <?php echo date('Y'); ?></p>
                </div>
            </div>
            
            <?php if ($user_logged_in): ?>
                <div style="display: flex; align-items: center; gap: 20px;">
                    <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['nama_lengkap'] ?? $_SESSION['username']); ?></span>
                    <a href="/logout.php" class="btn-auth btn-logout"><i class="fas fa-sign-out-alt"></i> Keluar</a>
                </div>
            <?php else: ?>
                <div class="auth-buttons">
                    <a href="/login.php" class="btn-auth btn-login"><i class="fas fa-sign-in-alt"></i> Masuk</a>
                    <a href="/register.php" class="btn-auth btn-register"><i class="fas fa-user-plus"></i> Daftar</a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- HERO IMAGE DENGAN QR CODE 3D -->
        <div class="hero-section">
            <div class="hero-content">
                <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 20px;">
                    <img src="/assets/images/message-hero.png" alt="Hero" class="hero-image" style="max-width: 200px;">
                    <div>
                        <h2 class="hero-title">Selamat Datang di RMA</h2>
                        <p class="hero-text">Platform komunikasi terpadu SMKN 12 Jakarta. Kirim pesan, dapatkan respon cepat, dan pantau progress dengan mudah.</p>
                    </div>
                </div>
            </div>
            
            <!-- QR CODE 3D -->
            <div class="qr-container">
                <div id="qrcode"></div>
                <div class="qr-label">
                    <i class="fas fa-qrcode"></i> Scan untuk akses mobile
                </div>
                <small style="color: #6c757d; margin-top: 5px; font-size: 11px;"><?php echo $fullUrl; ?></small>
            </div>
        </div>
        
        <!-- INFO LAYANAN DARI KONFIGURASI TERPUSAT -->
        <div class="service-info">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <i class="fas fa-info-circle me-2 text-primary"></i>
                    <strong>Status Layanan (dari settings.php):</strong>
                </div>
                <div>
                    <span class="service-badge mailersend">
                        <i class="fas fa-envelope me-1"></i> MailerSend: <?php echo ($mailersendConfig['is_active'] ?? 0) ? 'Aktif' : 'Nonaktif'; ?>
                    </span>
                    <span class="service-badge fonnte">
                        <i class="fab fa-whatsapp me-1"></i> Fonnte: <?php echo ($fonnteConfig['is_active'] ?? 0) ? 'Aktif' : 'Nonaktif'; ?>
                    </span>
                    <span class="badge bg-secondary ms-2">From: <?php echo $mailersendConfig['from_email'] ?? 'N/A'; ?></span>
                </div>
            </div>
            <small class="text-muted d-block mt-2">
                <i class="fas fa-check-circle text-success me-1"></i>
                Konfigurasi dikelola terpusat melalui menu Admin → Settings
            </small>
        </div>
        
        <!-- INFO BOX -->
        <div class="info-box" style="background: #e8f0fe; padding: 15px; border-radius: 10px; margin-bottom: 25px;">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <i class="fas fa-info-circle"></i> Sistem akan mencegah pengiriman pesan yang sama dalam 24 jam.
                </div>
            </div>
        </div>
        
        <!-- Status Notifikasi dari Session -->
        <?php if (isset($_SESSION['message_notifications'])): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas fa-bell fa-2x me-3"></i>
                <div>
                    <strong>Status Notifikasi Pesan #<?php echo $_SESSION['message_notifications']['reference']; ?>:</strong><br>
                    <?php if ($_SESSION['message_notifications']['email']): ?>
                        <span class="badge bg-success me-2"><i class="fas fa-check"></i> Email terkirim</span>
                    <?php elseif ($_SESSION['message_notifications']['email'] === false && isset($_SESSION['message_notifications']['email_error'])): ?>
                        <span class="badge bg-warning me-2"><i class="fas fa-exclamation-triangle"></i> Email: <?php echo $_SESSION['message_notifications']['email_error']; ?></span>
                    <?php endif; ?>
                    
                    <?php if ($_SESSION['message_notifications']['whatsapp']): ?>
                        <span class="badge bg-success me-2"><i class="fab fa-whatsapp"></i> WhatsApp terkirim</span>
                    <?php elseif ($_SESSION['message_notifications']['whatsapp'] === false && isset($_SESSION['message_notifications']['wa_error'])): ?>
                        <span class="badge bg-warning me-2"><i class="fab fa-whatsapp"></i> WA: <?php echo $_SESSION['message_notifications']['wa_error']; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['message_notifications']); endif; ?>
        
        <!-- DEBUG PANEL -->
        <?php if (!empty($debug_steps)): ?>
        <div class="debug-panel" id="debugPanel">
            <div class="debug-header" onclick="toggleDebugContent()">
                <h3><i class="fas fa-bug"></i> DEBUG LOG - <?php echo count($debug_steps); ?> STEPS</h3>
                <i class="fas fa-chevron-down" id="debugContentToggleIcon"></i>
            </div>
            <div class="debug-content" id="debugContent">
                <?php foreach ($debug_steps as $step): ?>
                    <div class="debug-entry <?php echo $step['type']; ?>">
                        <div>
                            <span class="debug-step">STEP <?php echo str_pad($step['step'], 2, '0', STR_PAD_LEFT); ?></span>
                            <span><?php echo htmlspecialchars($step['title']); ?></span>
                            <span class="debug-time"><?php echo $step['time']; ?></span>
                        </div>
                        <div class="debug-caller"><?php echo $step['caller']; ?>:<?php echo $step['line']; ?></div>
                        <?php if ($step['data'] !== null): ?>
                            <div class="debug-data"><pre><?php echo htmlspecialchars(print_r($step['data'], true)); ?></pre></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- NOTIFIKASI -->
        <?php echo $db_error ?? ''; ?>
        <?php echo $message; ?>
        <?php echo $error; ?>
        
        <!-- ==================================================== -->
        <!-- FITUR LACAK PESAN - VERSION RAMPING -->
        <!-- ==================================================== -->
        <div class="tracking-section">
            <h2>
                <i class="fas fa-search-location"></i>
                Lacak Status Pesan
            </h2>
            
            <form method="POST" action="" id="trackingForm">
                <input type="hidden" name="track_message" value="1">
                
                <div class="tracking-input-group">
                    <div class="tracking-input">
                        <label for="tracking_reference">
                            <i class="fas fa-hashtag"></i> Nomor Referensi
                        </label>
                        <input type="text" 
                               class="form-control" 
                               id="tracking_reference" 
                               name="tracking_reference" 
                               value="<?php echo htmlspecialchars($tracking_reference); ?>"
                               placeholder="EXT20260219-18588 / MSG-20260219-84BC10"
                               required>
                    </div>
                    
                    <button type="submit" class="btn-track" id="trackBtn">
                        <i class="fas fa-search"></i> Lacak
                    </button>
                    
                    <?php if (!empty($tracking_reference)): ?>
                    <a href="?reset_form=1" class="btn-secondary-sm">
                        <i class="fas fa-times"></i> Reset
                    </a>
                    <?php endif; ?>
                </div>
                <small class="text-muted d-block mt-1">
                    <i class="fas fa-info-circle"></i> Masukkan nomor referensi yang diberikan saat mengirim pesan
                </small>
            </form>
            
            <!-- HASIL LACAK PESAN -->
            <?php if (!empty($tracking_result)): ?>
            <div class="tracking-result">
                <?php echo $tracking_result; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- FORM KIRIM PESAN -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2><i class="fas fa-paper-plane"></i> Kirim Pesan Tanpa Login</h2>
                <a href="?reset_form=1" class="refresh-btn" onclick="return confirm('Yakin ingin mereset form? Semua input yang belum disimpan akan hilang.');">
                    <i class="fas fa-sync-alt"></i> Refresh Form
                </a>
            </div>
            
            <form method="POST" action="" id="messageForm" enctype="multipart/form-data">
                <input type="hidden" name="form_unique_id" value="<?php echo $_SESSION['form_unique_id']; ?>">
                <input type="hidden" name="submit_external_message" value="1">
                
                <div class="grid-2">
                    <div class="form-group">
                        <label class="required">Nama Lengkap</label>
                        <input type="text" class="form-control" name="nama_pengirim" 
                               value="<?php echo htmlspecialchars($_POST['nama_pengirim'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email <span class="badge-mailersend ms-1">MailerSend</span></label>
                        <input type="email" class="form-control" name="email_pengirim" 
                               value="<?php echo htmlspecialchars($_POST['email_pengirim'] ?? ''); ?>"
                               placeholder="email@contoh.com">
                        <small class="text-muted">Notifikasi akan dikirim ke email ini (opsional)</small>
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label>Nomor HP <span class="badge-fonnte ms-1">Fonnte</span></label>
                        <input type="tel" class="form-control" name="nomor_hp" 
                               value="<?php echo htmlspecialchars($_POST['nomor_hp'] ?? ''); ?>"
                               placeholder="08123456789">
                        <small class="text-muted">Notifikasi WhatsApp akan dikirim ke nomor ini (opsional)</small>
                    </div>
                    <div class="form-group">
                        <label class="required">Identitas</label>
                        <select class="form-control" name="identitas" required>
                            <option value="">-- Pilih Identitas --</option>
                            
                            <!-- Internal Sekolah - DITAMBAHKAN OPSI SISWA -->
                            <optgroup label="INTERNAL SEKOLAH">
                                <option value="siswa">Siswa</option>
                                <option value="guru">Guru</option>
                                <option value="staff_tu">Staff Tata Usaha</option>
                            </optgroup>
                            
                            <!-- Alumni -->
                            <optgroup label="ALUMNI">
                                <option value="alumni">Alumni</option>
                            </optgroup>
                            
                            <!-- Orang Tua / Wali -->
                            <optgroup label="ORANG TUA / WALI">
                                <option value="orang_tua">Orang Tua/Wali Siswa</option>
                            </optgroup>
                            
                            <!-- Masyarakat Umum -->
                            <optgroup label="MASYARAKAT">
                                <option value="masyarakat">Masyarakat</option>
                            </optgroup>
                            
                            <!-- Institusi / Lembaga -->
                            <optgroup label="INSTITUSI / LEMBAGA">
                                <option value="instansi">Instansi/Institusi</option>
                            </optgroup>
                            
                            <!-- Kemitraan -->
                            <optgroup label="KEMITRAAN">
                                <option value="kemitraan">Kemitraan</option>
                            </optgroup>
                        </select>
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label class="required">Jenis Pesan</label>
                        <select class="form-control" name="jenis_pesan_id" required>
                            <option value="">-- Pilih --</option>
                            <?php
                            try {
                                $types = $db->select("SELECT id, jenis_pesan FROM message_types WHERE is_active = 1");
                                foreach ($types as $type) {
                                    $selected = (isset($_POST['jenis_pesan_id']) && $_POST['jenis_pesan_id'] == $type['id']) ? 'selected' : '';
                                    echo '<option value="' . $type['id'] . '" ' . $selected . '>' . $type['jenis_pesan'] . '</option>';
                                }
                            } catch (Exception $e) {
                                echo '<option value="1">Informasi</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Prioritas</label>
                        <select class="form-control" name="prioritas">
                            <option value="Low" <?php echo (isset($_POST['prioritas']) && $_POST['prioritas'] == 'Low') ? 'selected' : ''; ?>>🔵 Rendah</option>
                            <option value="Medium" <?php echo (!isset($_POST['prioritas']) || $_POST['prioritas'] == 'Medium') ? 'selected' : ''; ?>>🟡 Sedang</option>
                            <option value="High" <?php echo (isset($_POST['prioritas']) && $_POST['prioritas'] == 'High') ? 'selected' : ''; ?>>🔴 Tinggi</option>
                        </select>
                        <small class="text-muted">Prioritas: Rendah, Sedang, Tinggi</small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="required">Isi Pesan</label>
                    <textarea class="form-control" name="isi_pesan" rows="5" required><?php echo htmlspecialchars($_POST['isi_pesan'] ?? ''); ?></textarea>
                </div>
                
                <!-- ========================================================= -->
                <!-- FITUR UPLOAD GAMBAR (OPSIONAL) -->
                <!-- ========================================================= -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-images me-1"></i> Lampiran Gambar <span class="text-muted">(Opsional)</span>
                    </label>
                    
                    <div class="upload-area" id="uploadArea">
                        <div class="upload-icon">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <h6 class="mb-2">Klik untuk pilih gambar atau drag & drop</h6>
                        <p class="text-muted small mb-3">Format: JPG, JPEG, PNG, GIF, WEBP, HEIC, BMP (Max 5MB per file)</p>
                        
                        <div class="d-flex justify-content-center">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="selectFilesBtn">
                                <i class="fas fa-folder-open me-1"></i> Pilih File
                            </button>
                        </div>
                        
                        <input type="file" 
                               class="d-none" 
                               id="attachments" 
                               name="attachments[]" 
                               multiple 
                               accept=".jpg,.jpeg,.png,.gif,.webp,.heic,.heif,.bmp,image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif,image/bmp">
                    </div>
                    
                    <!-- Preview Area -->
                    <div id="previewArea" class="row g-2 mt-3"></div>
                    
                    <div class="form-text text-muted small">
                        <i class="fas fa-info-circle me-1"></i>
                        Anda dapat memilih lebih dari satu gambar. Hanya gambar yang akan ditampilkan di preview.
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="captcha" required <?php echo isset($_POST['captcha']) ? 'checked' : ''; ?>> Saya bukan robot
                    </label>
                </div>
                
                <div class="button-group">
                    <button type="submit" class="btn-primary" id="submitBtn">
                        <i class="fas fa-paper-plane"></i> Kirim Pesan
                    </button>
                    <a href="?reset_form=1" class="refresh-btn" onclick="return confirm('Yakin ingin mereset form? Semua input yang belum disimpan akan hilang.');">
                        <i class="fas fa-sync-alt"></i> Refresh Form
                    </a>
                </div>
            </form>
        </div>
        
        <div class="footer">
            <small>© <?php echo date('Y'); ?> SMKN 12 Jakarta</small>
        </div>
    </div>
    
    <script>
        // Dark Mode Toggle Function
        function toggleDarkMode() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const toggleBtn = document.getElementById('darkModeToggle');
            const toggleText = document.getElementById('darkModeText');
            
            if (currentTheme === 'dark') {
                html.removeAttribute('data-theme');
                toggleBtn.innerHTML = '<i class="fas fa-moon"></i> <span id="darkModeText">Dark Mode</span>';
                localStorage.setItem('theme', 'light');
            } else {
                html.setAttribute('data-theme', 'dark');
                toggleBtn.innerHTML = '<i class="fas fa-sun"></i> <span id="darkModeText">Light Mode</span>';
                localStorage.setItem('theme', 'dark');
            }
        }

        // Check for saved theme preference
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme');
            const toggleBtn = document.getElementById('darkModeToggle');
            const toggleText = document.getElementById('darkModeText');
            
            if (savedTheme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
                toggleBtn.innerHTML = '<i class="fas fa-sun"></i> <span id="darkModeText">Light Mode</span>';
            } else {
                document.documentElement.removeAttribute('data-theme');
                toggleBtn.innerHTML = '<i class="fas fa-moon"></i> <span id="darkModeText">Dark Mode</span>';
            }
        });

        function toggleDebugPanel() {
            document.getElementById('debugPanel').classList.toggle('visible');
        }
        function toggleDebugContent() {
            document.getElementById('debugContent').classList.toggle('show');
        }
        
        // =========================================================
        // FITUR UPLOAD GAMBAR
        // =========================================================
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('attachments');
        const selectFilesBtn = document.getElementById('selectFilesBtn');
        const previewArea = document.getElementById('previewArea');
        
        // Klik pada area upload atau tombol untuk memilih file
        if (uploadArea) {
            uploadArea.addEventListener('click', function() {
                fileInput.click();
            });
        }
        
        if (selectFilesBtn) {
            selectFilesBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                fileInput.click();
            });
        }
        
        // Drag & Drop
        if (uploadArea) {
            uploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('dragover');
            });
            
            uploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
            });
            
            uploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    handleFiles(files);
                }
            });
        }
        
        // Saat file dipilih
        if (fileInput) {
            fileInput.addEventListener('change', function() {
                handleFiles(this.files);
            });
        }
        
        // Fungsi untuk handle files
        function handleFiles(files) {
            if (!previewArea) return;
            
            previewArea.innerHTML = ''; // Kosongkan preview
            
            if (files.length === 0) return;
            
            // Batasi maksimal 5 file
            if (files.length > 5) {
                alert('Maksimal 5 file yang dapat diupload sekaligus');
                fileInput.value = '';
                return;
            }
            
            const allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp'];
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            Array.from(files).forEach((file, index) => {
                // Validasi ekstensi
                const extension = file.name.split('.').pop().toLowerCase();
                if (!allowedExtensions.includes(extension)) {
                    showPreviewError(file, 'Format tidak didukung');
                    return;
                }
                
                // Validasi ukuran
                if (file.size > maxSize) {
                    showPreviewError(file, 'File >5MB');
                    return;
                }
                
                // Preview gambar
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    
                    reader.onload = function(e) {
                        const previewCol = document.createElement('div');
                        previewCol.className = 'col-6 col-md-4 col-lg-3';
                        previewCol.innerHTML = `
                            <div class="preview-item">
                                <img src="${e.target.result}" alt="${file.name}">
                                <div class="preview-info">
                                    <span class="text-truncate" style="max-width: 70px;">${file.name.substring(0, 10)}${file.name.length > 10 ? '...' : ''}</span>
                                    <span class="file-size">${(file.size / 1024).toFixed(1)} KB</span>
                                </div>
                                <button type="button" class="remove-file" data-index="${index}" title="Hapus">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        `;
                        
                        previewArea.appendChild(previewCol);
                        
                        // Tombol hapus
                        previewCol.querySelector('.remove-file').addEventListener('click', function(e) {
                            e.stopPropagation();
                            removeFile(index);
                        });
                    };
                    
                    reader.readAsDataURL(file);
                } else {
                    showPreviewError(file, 'Bukan gambar');
                }
            });
        }
        
        function showPreviewError(file, error) {
            if (!previewArea) return;
            
            const previewCol = document.createElement('div');
            previewCol.className = 'col-6 col-md-4 col-lg-3';
            previewCol.innerHTML = `
                <div class="preview-item border border-danger">
                    <div class="bg-light d-flex align-items-center justify-content-center" style="height: 100px;">
                        <i class="fas fa-exclamation-triangle text-danger fa-2x"></i>
                    </div>
                    <div class="preview-info bg-danger">
                        <span class="text-truncate" style="max-width: 70px;">${file.name.substring(0, 10)}${file.name.length > 10 ? '...' : ''}</span>
                        <span class="file-size">${error}</span>
                    </div>
                    <button type="button" class="remove-file" title="Hapus">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            previewArea.appendChild(previewCol);
            
            previewCol.querySelector('.remove-file').addEventListener('click', function() {
                removeFile(null);
            });
        }
        
        function removeFile(index) {
            // Untuk demo sederhana, kita reload preview
            // Dalam implementasi sebenarnya, perlu manipulasi FileList
            if (fileInput) {
                fileInput.value = '';
            }
            if (previewArea) {
                previewArea.innerHTML = '';
            }
        }
        
        // =========================================================
        // VALIDASI FORM
        // =========================================================
        document.getElementById('messageForm').addEventListener('submit', function(e) {
            const nama = document.querySelector('[name="nama_pengirim"]').value;
            const identitas = document.querySelector('[name="identitas"]').value;
            const jenisPesan = document.querySelector('[name="jenis_pesan_id"]').value;
            const isiPesan = document.querySelector('[name="isi_pesan"]').value;
            
            if (!nama || !identitas || !jenisPesan || !isiPesan) {
                e.preventDefault();
                alert('Harap isi semua field yang wajib diisi!');
                return false;
            }
            
            if (isiPesan.length < 10) {
                e.preventDefault();
                alert('Isi pesan minimal 10 karakter!');
                return false;
            }
            
            // Validasi jumlah file (maksimal 5)
            if (fileInput && fileInput.files.length > 5) {
                e.preventDefault();
                alert('Maksimal 5 file yang dapat diupload sekaligus');
                return false;
            }
            
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengirim...';
            submitBtn.disabled = true;
        });
        
        document.getElementById('trackingForm').addEventListener('submit', function(e) {
            const reference = document.getElementById('tracking_reference').value;
            if (!reference.trim()) {
                e.preventDefault();
                alert('Harap masukkan nomor referensi!');
                return false;
            }
            
            const trackBtn = document.getElementById('trackBtn');
            trackBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mencari...';
            trackBtn.disabled = true;
            
            // Re-enable after 10 seconds in case of stuck
            setTimeout(() => {
                trackBtn.innerHTML = '<i class="fas fa-search"></i> Lacak';
                trackBtn.disabled = false;
            }, 10000);
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            const url = '<?php echo $fullUrl; ?>';
            
            const qr = qrcode(0, 'H');
            qr.addData(url);
            qr.make();
            
            const canvas = document.createElement('canvas');
            const size = 200;
            canvas.width = size;
            canvas.height = size;
            
            const ctx = canvas.getContext('2d');
            const moduleCount = qr.getModuleCount();
            const moduleSize = size / moduleCount;
            
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, size, size);
            
            ctx.fillStyle = '#000000';
            for (let row = 0; row < moduleCount; row++) {
                for (let col = 0; col < moduleCount; col++) {
                    if (qr.isDark(row, col)) {
                        ctx.fillRect(
                            col * moduleSize,
                            row * moduleSize,
                            moduleSize,
                            moduleSize
                        );
                    }
                }
            }
            
            ctx.strokeStyle = '#0b4d8a';
            ctx.lineWidth = 3;
            ctx.strokeRect(2, 2, size - 4, size - 4);
            
            ctx.fillStyle = '#0b4d8a';
            ctx.beginPath();
            ctx.arc(size/2, size/2, 15, 0, 2 * Math.PI);
            ctx.fill();
            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold 12px Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText('RMA', size/2, size/2);
            
            const qrContainer = document.getElementById('qrcode');
            qrContainer.innerHTML = '';
            qrContainer.appendChild(canvas);
        });
    </script>
</body>
</html>